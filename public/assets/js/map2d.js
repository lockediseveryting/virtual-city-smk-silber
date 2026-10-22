/**
 * 2D Interactive Campus Map & Minimap Fallback
 * Works smoothly on all mobile devices and low-end browsers without WebGL.
 * Features: Dynamic auto-fit, player radar tracking, zoom in/out, pan drag,
 * rendering of campus buildings, futsal court, student parking, and outdoor gardens.
 */
class CampusMap2D {
  constructor(canvasId, options = {}) {
    this.canvas = document.getElementById(canvasId);
    if (!this.canvas) throw new Error("Canvas 2D element not found: " + canvasId);
    this.ctx = this.canvas.getContext('2d');
    this.options = Object.assign({
      campusDataUrl: '/api/campus',
      currentFloor: 1,
      interactive: true,
      trackPlayer: false,
      autoFit: false,
      scale: 1.1,
      onSelectRoom: null
    }, options);

    this.campusData = null;
    this.currentFloor = this.options.currentFloor;
    this.playerMarker = { x: 135, z: 12, heading: 0 };
    this.otherPlayers = new Map();
    this.hoveredRoom = null;
    this.selectedRoom = null;

    // --- Lapisan lokasi dokumen mapping (locations.json, koordinat ternormalisasi 0-1000 x 0-600) ---
    this.locationsDoc = null;
    this.locations = [];
    this.locationById = new Map();
    this.locationFilter = 'semua';
    this.locationQuery = '';
    this.selectedLocationId = null;
    this.hoveredLocationId = null;
    // Kalibrasi rentang koordinat dokumen -> ruang dunia meter (perkiraan, bisa disetel ulang)
    this.locBounds = Object.assign({ minX: -196, maxX: 190, minZ: -102, maxZ: 84,
        docW: 1000, docH: 600 }, options.locBounds || {});
    this.categoryColors = {
      belajar: '#22d3ee', layanan: '#f59e0b', olahraga: '#22c55e', fasilitas: '#a78bfa'
    };
    this.categoryIcons = { belajar: '📚', layanan: '🏛️', olahraga: '⚽', fasilitas: '🌳' };

    // Viewport transform
    this.scale = this.options.scale || 1.1;
    this.offsetX = this.canvas.width / 2;
    this.offsetY = this.canvas.height / 2;
    this.isDragging = false;
    this.dragStart = { x: 0, y: 0 };
    this.dragMoved = false;
  }

  syncOtherPlayers(players) {
    if (!players) return;
    this.otherPlayers.clear();
    if (Array.isArray(players)) {
      players.forEach(p => {
        if (p && p.id) this.otherPlayers.set(p.id, p);
      });
    } else if (players instanceof Map) {
      for (const [id, rec] of players.entries()) {
        const p = rec.data || rec;
        const pos = rec.targetPos || rec;
        this.otherPlayers.set(id, {
          id: id,
          username: p.username || 'Pemain',
          fullName: p.fullName || p.username || 'Pemain',
          role: p.role || 'murid',
          x: pos.x,
          z: pos.z,
          floor: rec.floor || p.floor || 1,
          yaw: rec.targetYaw !== undefined ? rec.targetYaw : (p.yaw || 0)
        });
      }
    }
    this.render();
  }

  async init() {
    try {
      const res = await fetch(this.options.campusDataUrl);
      this.campusData = await res.json();
    } catch(e) {
      console.warn("Gagal load campus data untuk map 2D:", e);
    }

    if (this.options.interactive) {
      this.setupEventListeners();
    }
    this.resizeCanvas();
    if (this.options.autoFit) {
      this.fitToCampus();
    }
    this.render();
  }

  resizeCanvas() {
    // Pixel-art tajam & konsisten: tanpa smoothing canvas maupun CSS.
    this.ctx.imageSmoothingEnabled = false;
    this.canvas.style.imageRendering = 'pixelated';
    const parent = this.canvas.parentElement;
    if (parent && parent.clientWidth > 0) {
      this.canvas.width = parent.clientWidth;
      this.canvas.height = parent.clientHeight || 500;
    }
    if (this.options.autoFit) {
      this.fitToCampus();
    } else if (this.options.trackPlayer) {
      this.centerOnPlayer();
    } else {
      this.offsetX = this.canvas.width / 2;
      this.offsetY = this.canvas.height / 2;
    }
    this.render();
  }

  fitToCampus() {
    if (!this.campusData || !this.campusData.buildings || this.campusData.buildings.length === 0) {
      this.offsetX = this.canvas.width / 2;
      this.offsetY = this.canvas.height / 2;
      return;
    }

    let minX = 9999, maxX = -9999, minZ = 9999, maxZ = -9999;
    this.campusData.buildings.forEach(b => {
      const hw = (b.bounds.width || 20) / 2;
      const hd = (b.bounds.depth || 20) / 2;
      minX = Math.min(minX, b.bounds.x - hw);
      maxX = Math.max(maxX, b.bounds.x + hw);
      minZ = Math.min(minZ, b.bounds.z - hd);
      maxZ = Math.max(maxZ, b.bounds.z + hd);
    });

    if (this.campusData.sports && this.campusData.sports.futsal) {
      const f = this.campusData.sports.futsal;
      minX = Math.min(minX, f.x - f.width / 2);
      maxX = Math.max(maxX, f.x + f.width / 2);
      minZ = Math.min(minZ, f.z - f.depth / 2);
      maxZ = Math.max(maxZ, f.z + f.depth / 2);
    }

    if (this.campusData.parking && this.campusData.parking.student) {
      const p = this.campusData.parking.student;
      minX = Math.min(minX, p.x - p.width / 2);
      maxX = Math.max(maxX, p.x + p.width / 2);
      minZ = Math.min(minZ, p.z - p.depth / 2);
      maxZ = Math.max(maxZ, p.z + p.depth / 2);
    }

    // Extra margin
    minX -= 25; maxX += 25;
    minZ -= 25; maxZ += 25;

    const spanW = maxX - minX;
    const spanD = maxZ - minZ;
    const pad = 24;
    const availW = Math.max(100, this.canvas.width - pad * 2);
    const availH = Math.max(100, this.canvas.height - pad * 2);

    this.scale = Math.min(availW / spanW, availH / spanD);
    const midX = (minX + maxX) / 2;
    const midZ = (minZ + maxZ) / 2;
    this.offsetX = this.canvas.width / 2 - midX * this.scale;
    this.offsetY = this.canvas.height / 2 - midZ * this.scale;
    this.render();
  }

  centerOnPlayer() {
    this.offsetX = this.canvas.width / 2 - this.playerMarker.x * this.scale;
    this.offsetY = this.canvas.height / 2 - this.playerMarker.z * this.scale;
  }

  zoomIn(factor = 1.25) {
    this.scale = Math.min(4.0, this.scale * factor);
    this.render();
  }

  zoomOut(factor = 0.8) {
    this.scale = Math.max(0.35, this.scale * factor);
    this.render();
  }

  setupEventListeners() {
    const getCanvasCoords = (clientX, clientY) => {
      const rect = this.canvas.getBoundingClientRect();
      const scaleX = rect.width > 0 ? (this.canvas.width / rect.width) : 1;
      const scaleY = rect.height > 0 ? (this.canvas.height / rect.height) : 1;
      return {
        x: (clientX - rect.left) * scaleX,
        y: (clientY - rect.top) * scaleY
      };
    };

    // Pan with mouse drag
    this.canvas.addEventListener('mousedown', e => {
      this.isDragging = true;
      this.dragStart = { x: e.clientX, y: e.clientY };
      this.dragMoved = false;
    });

    window.addEventListener('mousemove', e => {
      if (this.isDragging) {
        const dx = e.clientX - this.dragStart.x;
        const dy = e.clientY - this.dragStart.y;
        if (Math.abs(dx) > 3 || Math.abs(dy) > 3) this.dragMoved = true;
        this.offsetX += dx;
        this.offsetY += dy;
        this.dragStart = { x: e.clientX, y: e.clientY };
        this.render();
      } else {
        const coords = getCanvasCoords(e.clientX, e.clientY);
        const world = this.screenToWorld(coords.x, coords.y);
        const prevHover = this.hoveredRoom;
        this.hoveredRoom = this.findRoomAt(world.x, world.z);
        const prevHoverLoc = this.hoveredLocationId;
        const hLoc = this.visibleLocationAt(world.x, world.z);
        this.hoveredLocationId = hLoc ? hLoc.id : null;
        if (this.hoveredRoom !== prevHover || this.hoveredLocationId !== prevHoverLoc) this.render();
      }
    });

    window.addEventListener('mouseup', () => {
      this.isDragging = false;
    });

    // Zoom with mouse wheel
    this.canvas.addEventListener('wheel', e => {
      e.preventDefault();
      const zoom = e.deltaY < 0 ? 1.15 : 0.87;
      const coords = getCanvasCoords(e.clientX, e.clientY);
      // Zoom centered on cursor
      const worldX = (coords.x - this.offsetX) / this.scale;
      const worldZ = (coords.y - this.offsetY) / this.scale;
      this.scale = Math.max(0.35, Math.min(4.0, this.scale * zoom));
      this.offsetX = coords.x - worldX * this.scale;
      this.offsetY = coords.y - worldZ * this.scale;
      this.render();
    }, { passive: false });

    const handleInteraction = (clientX, clientY) => {
      if (this.dragMoved) return; // Drag occurred, not a click!
      const coords = getCanvasCoords(clientX, clientY);
      const world = this.screenToWorld(coords.x, coords.y);
      // Prioritas: hotspot lokasi dokumen mapping (locations.json) di atas gedung generik.
      const loc = this.visibleLocationAt(world.x, world.z);
      if (loc) {
        this.selectedLocationId = loc.id;
        this.selectedRoom = null;
        if (typeof this.options.onSelectLocation === 'function') {
          this.options.onSelectLocation(loc);
        }
        this.render();
        return;
      }
      const room = this.findRoomAt(world.x, world.z);
      if (room) {
        this.selectedRoom = room;
        if (typeof this.options.onSelectRoom === 'function') {
          this.options.onSelectRoom(room);
        }
      }
      this.render();
    };

    this.canvas.addEventListener('click', e => {
      handleInteraction(e.clientX, e.clientY);
    });

    this.canvas.addEventListener('touchstart', e => {
      if (e.touches && e.touches.length > 0) {
        this.dragStart = { x: e.touches[0].clientX, y: e.touches[0].clientY };
        this.dragMoved = false;
      }
    }, { passive: true });

    this.canvas.addEventListener('touchmove', e => {
      if (e.touches && e.touches.length === 1) {
        const dx = e.touches[0].clientX - this.dragStart.x;
        const dy = e.touches[0].clientY - this.dragStart.y;
        if (Math.abs(dx) > 4 || Math.abs(dy) > 4) this.dragMoved = true;
        this.offsetX += dx;
        this.offsetY += dy;
        this.dragStart = { x: e.touches[0].clientX, y: e.touches[0].clientY };
        this.render();
      }
    }, { passive: true });

    this.canvas.addEventListener('touchend', e => {
      if (!this.dragMoved && e.changedTouches && e.changedTouches.length > 0) {
        handleInteraction(e.changedTouches[0].clientX, e.changedTouches[0].clientY);
      }
    }, { passive: true });
  }

  worldToScreen(x, z) {
    return {
      x: this.offsetX + x * this.scale,
      y: this.offsetY + z * this.scale
    };
  }

  screenToWorld(sx, sy) {
    return {
      x: (sx - this.offsetX) / this.scale,
      z: (sy - this.offsetY) / this.scale
    };
  }

  findRoomAt(x, z) {
    if (!this.campusData) return null;
    // Check specific rooms first
    if (this.campusData.rooms) {
      for (const r of this.campusData.rooms) {
        if ((r.floor || 1) !== this.currentFloor) continue;
        const rx = (r.x !== undefined) ? r.x : (r.bounds ? r.bounds.x : 0);
        const rz = (r.z !== undefined) ? r.z : (r.bounds ? r.bounds.z : 0);
        const dist = Math.hypot(x - rx, z - rz);
        if (dist <= 18) {
          return Object.assign({ x: rx, z: rz }, r);
        }
      }
    }
    // Fall back to building bounds
    if (this.campusData.buildings) {
      const pad = 10 / this.scale;
      for (const b of this.campusData.buildings) {
        if (b.floor !== this.currentFloor && !b.hasFloor2) continue;
        const minX = b.bounds.x - b.bounds.width / 2 - pad;
        const maxX = b.bounds.x + b.bounds.width / 2 + pad;
        const minZ = b.bounds.z - b.bounds.depth / 2 - pad;
        const maxZ = b.bounds.z + b.bounds.depth / 2 + pad;
        if (x >= minX && x <= maxX && z >= minZ && z <= maxZ) {
          return Object.assign({ x: b.bounds.x, z: b.bounds.z }, b);
        }
      }
    }
    return null;
  }

  setFloor(floorNum) {
    this.currentFloor = (floorNum === 2) ? 2 : 1;
    this.render();
  }

  updatePlayerPos(x, z, heading = 0) {
    this.playerMarker = { x, z, heading };
    if (this.options.trackPlayer) {
      this.centerOnPlayer();
    }
    this.render();
  }

  render() {
    const ctx = this.ctx;
    // Pixel-art tajam: matikan smoothing setiap render (context state bisa di-reset browser).
    ctx.imageSmoothingEnabled = false;
    ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

    // 1. Campus Ground / Deep Sky Canvas
    ctx.fillStyle = '#0f172a';
    ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);

    // 2. Subtle Coordinate Grid
    ctx.strokeStyle = '#1e293b';
    ctx.lineWidth = 1;
    const gridStep = 40 * this.scale;
    if (gridStep > 8) {
      const startX = this.offsetX % gridStep;
      for (let x = startX; x < this.canvas.width; x += gridStep) {
        ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, this.canvas.height); ctx.stroke();
      }
      const startY = this.offsetY % gridStep;
      for (let y = startY; y < this.canvas.height; y += gridStep) {
        ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(this.canvas.width, y); ctx.stroke();
      }
    }

    if (!this.campusData) return;

    // 3. Central Plaza Paving (Floor 1)
    if (this.currentFloor === 1) {
      const plazaP = this.worldToScreen(-230, -125);
      ctx.fillStyle = 'rgba(226, 232, 240, 0.12)';
      ctx.fillRect(plazaP.x, plazaP.y, 460 * this.scale, 280 * this.scale);
      ctx.strokeStyle = 'rgba(148, 163, 184, 0.25)';
      ctx.lineWidth = 1;
      ctx.strokeRect(plazaP.x, plazaP.y, 460 * this.scale, 280 * this.scale);

      // Walkways
      const walkways = [
        { x: 0, z: -130, w: 18, d: 150 },
        { x: 0, z: 130, w: 20, d: 110 },
        { x: -160, z: 15, w: 130, d: 16 },
        { x: 140, z: 15, w: 120, d: 16 }
      ];
      walkways.forEach(wk => {
        const wp = this.worldToScreen(wk.x - wk.w / 2, wk.z - wk.d / 2);
        ctx.fillStyle = 'rgba(203, 213, 225, 0.18)';
        ctx.fillRect(wp.x, wp.y, wk.w * this.scale, wk.d * this.scale);
      });
    }

    // 4. Student Parking Area
    if (this.currentFloor === 1) {
      const parkX = 145, parkZ = -55, parkW = 62, parkD = 42;
      const pp = this.worldToScreen(parkX - parkW / 2, parkZ - parkD / 2);
      ctx.fillStyle = '#1e293b';
      ctx.fillRect(pp.x, pp.y, parkW * this.scale, parkD * this.scale);
      ctx.strokeStyle = '#0284c7';
      ctx.lineWidth = 1.5;
      ctx.strokeRect(pp.x, pp.y, parkW * this.scale, parkD * this.scale);
      ctx.fillStyle = '#38bdf8';
      ctx.font = 'bold 9px sans-serif';
      ctx.fillText('🅿️ PARKIR SISWA', pp.x + 6, pp.y + 14);
    }

    // 5. Outdoor Cafeteria & Garden
    if (this.currentFloor === 1) {
      const cafeX = -75, cafeZ = -75, cafeW = 54, cafeD = 40;
      const cp = this.worldToScreen(cafeX - cafeW / 2, cafeZ - cafeD / 2);
      ctx.fillStyle = 'rgba(5, 150, 105, 0.25)';
      ctx.fillRect(cp.x, cp.y, cafeW * this.scale, cafeD * this.scale);
      ctx.strokeStyle = '#059669';
      ctx.lineWidth = 1.5;
      ctx.strokeRect(cp.x, cp.y, cafeW * this.scale, cafeD * this.scale);
      ctx.fillStyle = '#34d399';
      ctx.font = 'bold 9px sans-serif';
      ctx.fillText('☕ KANTIN & TAMAN', cp.x + 6, cp.y + 14);
    }

    // 6. Futsal Court
    if (this.campusData.sports && this.campusData.sports.futsal && this.currentFloor === 1) {
      const f = this.campusData.sports.futsal;
      const p = this.worldToScreen(f.x - f.width / 2, f.z - f.depth / 2);
      ctx.fillStyle = '#166534';
      ctx.fillRect(p.x, p.y, f.width * this.scale, f.depth * this.scale);
      ctx.strokeStyle = '#22c55e';
      ctx.lineWidth = 2;
      ctx.strokeRect(p.x, p.y, f.width * this.scale, f.depth * this.scale);
      ctx.fillStyle = '#ffffff';
      ctx.font = 'bold 10px sans-serif';
      ctx.fillText("⚽ LAPANGAN FUTSAL", p.x + 6, p.y + 16);
    }

    // 7. Fountain
    if (this.currentFloor === 1) {
      const fp = this.worldToScreen(-18, 16);
      ctx.beginPath();
      ctx.arc(fp.x, fp.y, 8 * this.scale, 0, Math.PI * 2);
      ctx.fillStyle = '#0284c7';
      ctx.fill();
      ctx.strokeStyle = '#38bdf8';
      ctx.lineWidth = 1.5;
      ctx.stroke();
    }

    // 8. Buildings
    if (this.campusData.buildings) {
      this.campusData.buildings.forEach(b => {
        if (b.floor !== this.currentFloor && !b.hasFloor2) return;

        const pos = this.worldToScreen(b.bounds.x - b.bounds.width / 2, b.bounds.z - b.bounds.depth / 2);
        const w = b.bounds.width * this.scale;
        const h = b.bounds.depth * this.scale;

        const isHover = (this.hoveredRoom && this.hoveredRoom.id === b.id);
        const isSel = (this.selectedRoom && this.selectedRoom.id === b.id);

        ctx.fillStyle = isSel ? '#0284c7' : (isHover ? '#38bdf8' : (b.roofColor || '#1e3a8a'));
        ctx.fillRect(pos.x, pos.y, w, h);

        ctx.strokeStyle = isSel ? '#facc15' : '#475569';
        ctx.lineWidth = isSel ? 3 : 1.5;
        ctx.strokeRect(pos.x, pos.y, w, h);

        // Building Label
        ctx.fillStyle = '#ffffff';
        const fontSize = Math.max(9, Math.min(13, Math.floor(10 * Math.sqrt(this.scale))));
        ctx.font = 'bold ' + fontSize + 'px sans-serif';
        if (this.options.compactLabels) {
          // Radar kecil: label hanya bila muat di kotak gedung (dulu saling menimpa & keluar kotak).
          if (w < 28 || h < fontSize + 6) return;
          // Nama pendek: "Gedung B (Ruang Kelas …)" → "Gedung B", "Perpustakaan SMK Silber" → "Perpustakaan".
          const short = b.short || String(b.name).replace(/\s*\(.*\)\s*$/, '').replace(/\s+SMK Silber$/i, '').replace(/\s*&.*$/, '');
          let name = short;
          if (ctx.measureText(name).width > w - 10) name = name.replace(/^Gedung\s+/i, '');
          while (name.length > 3 && ctx.measureText(name).width > w - 10) name = name.slice(0, -2);
          if (name.length < short.length && !/^[A-D]$/.test(name)) name = name.replace(/\s+$/, '') + '…';
          ctx.fillText(name, pos.x + 5, pos.y + fontSize + 3);
        } else {
          ctx.fillText(b.name, pos.x + 6, pos.y + fontSize + 4);
        }
      });
    }

    // 8.5 Lapisan hotspot lokasi dokumen mapping (locations.json)
    this._renderLocationHotspots(ctx);

    // 8.6 Other Remote Players on the same floor
    if (this.otherPlayers && this.otherPlayers.size > 0) {
      this.otherPlayers.forEach((op) => {
        if (op.floor !== this.currentFloor) return;
        const opPos = this.worldToScreen(op.x, op.z);
        const roleColor = (op.role === 'root' || op.role === 'admin') ? '#f97316' : (op.role === 'guru' ? '#f59e0b' : '#38bdf8');

        // Pulsing halo ring
        ctx.beginPath();
        ctx.arc(opPos.x, opPos.y, 7 * this.scale, 0, Math.PI * 2);
        ctx.strokeStyle = roleColor;
        ctx.globalAlpha = 0.45;
        ctx.lineWidth = 1.2;
        ctx.stroke();
        ctx.globalAlpha = 1.0;

        // Player dot
        ctx.beginPath();
        ctx.arc(opPos.x, opPos.y, 4.5, 0, Math.PI * 2);
        ctx.fillStyle = roleColor;
        ctx.fill();
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 1.2;
        ctx.stroke();

        // Direction pointer if yaw available
        if (op.yaw !== undefined) {
          ctx.save();
          ctx.translate(opPos.x, opPos.y);
          ctx.rotate(op.yaw);
          ctx.beginPath();
          ctx.moveTo(0, -7);
          ctx.lineTo(3, -2);
          ctx.lineTo(-3, -2);
          ctx.closePath();
          ctx.fillStyle = roleColor;
          ctx.fill();
          ctx.restore();
        }

        // Name Tag
        const nameText = op.fullName || op.username || 'Pemain';
        ctx.font = 'bold 9px sans-serif';
        const tw = ctx.measureText(nameText).width;
        ctx.fillStyle = 'rgba(15, 23, 42, 0.85)';
        ctx.fillRect(opPos.x - tw / 2 - 4, opPos.y - 17, tw + 8, 12);
        ctx.fillStyle = '#f8fafc';
        ctx.fillText(nameText, opPos.x - tw / 2, opPos.y - 8);
      });
    }

    // 9. Player Marker with Radar Pulse
    const pPos = this.worldToScreen(this.playerMarker.x, this.playerMarker.z);

    // Radar scan wave ring
    ctx.beginPath();
    ctx.arc(pPos.x, pPos.y, 10 * this.scale, 0, Math.PI * 2);
    ctx.strokeStyle = 'rgba(34, 211, 238, 0.45)';
    ctx.lineWidth = 1.5;
    ctx.stroke();

    ctx.save();
    ctx.translate(pPos.x, pPos.y);
    ctx.rotate(this.playerMarker.heading);

    // Glowing player dot
    ctx.beginPath();
    ctx.arc(0, 0, 5.5, 0, Math.PI * 2);
    ctx.fillStyle = '#22d3ee';
    ctx.fill();
    ctx.strokeStyle = '#ffffff';
    ctx.lineWidth = 1.5;
    ctx.stroke();

    // Direction arrow
    ctx.beginPath();
    ctx.moveTo(0, -9);
    ctx.lineTo(4, -3);
    ctx.lineTo(-4, -3);
    ctx.closePath();
    ctx.fillStyle = '#f59e0b';
    ctx.fill();

    ctx.restore();
  }

  /* ================= Lapisan lokasi dokumen mapping (35 ID) =================
   * Koordinat ternormalisasi 0-1000 x 0-600 dari locations.json dipetakan
   * linear ke dunia meter peta 2D. Hotspot, filter kategori, pencarian,
   * indikator lantai, dan seleksi semuanya berbasis sumber yang sama — dipakai
   * oleh mode 3D (via alias) dan denah interaktif.
   */

  async loadLocations(url = '/assets/map/locations.json') {
    try {
      const res = await fetch(url);
      const doc = await res.json();
      this.setLocations(doc);
    } catch (e) {
      console.warn('Gagal load locations.json:', e);
    }
    return this.locations;
  }

  setLocations(doc) {
    if (!doc || !Array.isArray(doc.locations)) return;
    this.locationsDoc = doc;
    this.locations = doc.locations;
    this.locationById = new Map(this.locations.map(l => [l.id, l]));
    if (doc.coordinates) {
      this.locBounds.docW = doc.coordinates.width || 1000;
      this.locBounds.docH = doc.coordinates.height || 600;
    }
    this.render();
  }

  /** Koordinat dokumen (0-1000 x 0-600) -> dunia meter peta 2D */
  locToWorld(nx, ny) {
    const b = this.locBounds;
    const fx = nx / b.docW, fy = ny / b.docH;
    return { x: b.minX + fx * (b.maxX - b.minX), z: b.minZ + fy * (b.maxZ - b.minZ) };
  }

  /** HashSet lokasi yang lolos filter kategori + pencarian + lantai aktif */
  _visibleLocations() {
    const q = (this.locationQuery || '').trim().toLowerCase();
    return this.locations.filter(l =>
      (l.floor || 1) === this.currentFloor &&
      (this.locationFilter === 'semua' || l.category === this.locationFilter) &&
      (!q || (l.name || '').toLowerCase().includes(q) || l.id.toLowerCase().includes(q) ||
        (l.description || '').toLowerCase().includes(q))
    );
  }

  visibleLocationAt(x, z) {
    const list = this._visibleLocations();
    let best = null, bestD = Infinity;
    for (const l of list) {
      const w = this.locToWorld(l.x, l.y);
      const d = Math.hypot(x - w.x, z - w.z);
      if (d <= (l.radius || 22) * 7 / this.scale && d < bestD) { best = l; bestD = d; }
    }
    return best;
  }

  setLocationFilter(category) {
    this.locationFilter = (this.categoryColors[category] || category === 'semua') ? category : 'semua';
    this.render();
  }

  searchLocations(q) {
    this.locationQuery = String(q || '');
    this.render();
  }

  selectLocation(id, opts = {}) {
    const l = this.locationById.get(id);
    if (!l) return false;
    if (!l.unlocked) return false;
    this.selectedLocationId = id;
    if (opts.jumpTo && (l.floor || 1) !== this.currentFloor) this.currentFloor = l.floor || 1;
    if (opts.centerOn) {
      const w = this.locToWorld(l.x, l.y);
      this.offsetX = this.canvas.width / 2 - w.x * this.scale;
      this.offsetY = this.canvas.height / 2 - w.z * this.scale;
    }
    if (typeof this.options.onSelectLocation === 'function') {
      this.options.onSelectLocation(l, opts);
    }
    this.render();
    return true;
  }

  _renderLocationHotspots(ctx) {
    if (!this.locations.length) return;
    const list = this._visibleLocations();
    const r = Math.max(4, Math.min(13, 7 * this.scale));
    for (const l of list) {
      const w = this.locToWorld(l.x, l.y);
      const p = this.worldToScreen(w.x, w.z);
      const isSel = l.id === this.selectedLocationId;
      const isHover = l.id === this.hoveredLocationId;
      const color = this.categoryColors[l.category] || '#38bdf8';

      // Lingkar kategori + ikon (ikon & label, bukan warna saja — aksesibilitas)
      ctx.beginPath(); ctx.arc(p.x, p.y, r, 0, Math.PI * 2);
      ctx.fillStyle = isSel ? color :
        (isHover ? 'rgba(255,255,255,.28)' : 'rgba(15, 23, 42, 0.82)');
      ctx.fill();
      ctx.strokeStyle = color;
      ctx.lineWidth = isSel ? 3 : 1.6;
      ctx.stroke();

      if (isSel) {
        ctx.beginPath(); ctx.arc(p.x, p.y, r + 4 + Math.sin(Date.now() / 220) * 1.2, 0, Math.PI * 2);
        ctx.strokeStyle = color; ctx.globalAlpha = 0.5; ctx.stroke(); ctx.globalAlpha = 1;
      }

      // Ikon kategori di tengah
      ctx.font = Math.round(r + 2) + 'px sans-serif';
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText(this.categoryIcons[l.category] || '📍', p.x, p.y + 0.5);
      ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';

      // Label nama (dipendam di zoom jauh)
      if ((this.scale > 0.9 && !this.options.compactLabels) || isSel || isHover) {
        const label = (l.missionIds && l.missionIds.length ? '★ ' : '') + (l.name || l.id);
        ctx.font = (isSel ? 'bold ' : '') + '9px sans-serif';
        const tw = ctx.measureText(label).width;
        ctx.fillStyle = 'rgba(9, 13, 22, 0.85)';
        ctx.fillRect(p.x - tw / 2 - 4, p.y - r - 17, tw + 8, 12);
        ctx.fillStyle = isSel ? color : '#e2e8f0';
        ctx.textAlign = 'center';
        ctx.fillText(label, p.x, p.y - r - 8);
        ctx.textAlign = 'left';
      }
    }
    // Ring panduan ke lokasi ber-offline? tidak — hanya ring hasil pencarian yang hicup
    if ((this.locationQuery || '').trim()) {
      for (const l of list) {
        const w = this.locToWorld(l.x, l.y);
        const p = this.worldToScreen(w.x, w.z);
        ctx.beginPath(); ctx.arc(p.x, p.y, r + 5, 0, Math.PI * 2);
        ctx.strokeStyle = '#facc15'; ctx.globalAlpha = 0.55; ctx.lineWidth = 1.2; ctx.stroke();
        ctx.globalAlpha = 1;
      }
    }
  }
}

window.CampusMap2D = CampusMap2D;
