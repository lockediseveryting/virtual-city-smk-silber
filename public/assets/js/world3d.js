/**
 * Virtual City SMK Silber v5 - Procedural 3D World Engine
 * Built with Three.js (self-hosted). Data-driven via campus.json.
 * Features: Soft aesthetic lighting, cozy campus atmosphere, 3D windows & architecture,
 * full mouse orbit controls (drag to look, wheel to zoom), and mobile touch gestures.
 */

// ===== Konstanta skala sinematik chibi/diorama (Workstream D) =====
// CHAR_SCALE : faktor pengecilan karakter pemain & NPC (chibi 0.4x).
// WORLD_SCALE: faktor pembesar dunia (diorama) — karakter terasa mungil di dunia besar.
// 14-10-2026: skala 0.2 (tinggi ~1.5 unit) membuat karakter seperti semut — pintu 7.5 unit,
// satu lantai 14 unit, bola futsal 2.4 unit. Skala 0.6 → tinggi ~4.7 unit (≈63% tinggi pintu),
// proporsional dengan gedung tapi tetap sedikit chibi. Kamera/collider/gerak disesuaikan.
const CHAR_SCALE = 1.2;
// Suasana per waktu: langit [atas, tengah, horizon], kabut = warna horizon, hemi [langit, tanah, kuat],
// matahari [warna, kuat], arah matahari, lampu malam.
const TIME_PRESETS = {
  pagi:  { label: '🌅 Pagi',  sky: ['#6d8fd6', '#f3b9a0', '#ffe2c4'], fog: 0xffe2c4, hemi: [0xffe8d6, 0x7a8f6b, 0.58], sun: [0xffd2a1, 1.0],  sunOffset: [90, 60, 60],    lamps: false },
  siang: { label: '☀️ Siang', sky: ['#3b82f6', '#93c5fd', '#cfe6f7'], fog: 0xcfe6f7, hemi: [0xdbeafe, 0x6b8f6b, 0.62], sun: [0xfff1d6, 1.2],  sunOffset: [70, 120, 50],   lamps: false },
  sore:  { label: '🌇 Sore',  sky: ['#3d4f96', '#e98a62', '#f5c49c'], fog: 0xf5c49c, hemi: [0xf2dccb, 0x6b5a4b, 0.5], sun: [0xffbb8c, 0.9], sunOffset: [-90, 55, 40],   lamps: false },
  malam: { label: '🌙 Malam', sky: ['#020617', '#0b1329', '#111827'], fog: 0x111827, hemi: [0x1e293b, 0x0f172a, 0.35], sun: [0x93c5fd, 0.45], sunOffset: [-60, 110, -50], lamps: true }
};
const WORLD_SCALE = 2.0;
const AVATAR_EFFECTIVE_SCALE = CHAR_SCALE / WORLD_SCALE; // 0.6
const PLAYER_EYE_Y = 4.3;       // tinggi mata (kamera orang-pertama)
const PLAYER_LOOK_Y = 3.2;      // titik pandang kamera orang-ketiga (dada/kepala)
const WALK_SPEED = 18;          // unit/detik (di bawah batas anti-speedhack WS 40 u/s)
const SPRINT_SPEED = 30;
const JUMP_VELOCITY = 19;       // unit/detik → puncak lompat ~3 unit
const GRAVITY = 60;             // unit/detik²
// Gedung bertingkat sungguhan (satu dunia kontinu, tanpa tombol LT 1/LT 2).
const FLOOR_H = 10;             // tinggi satu lantai (pintu 7.5, karakter ~4.7)
const FLOOR_BASE = 0.3;         // permukaan lantai dasar di dalam gedung (di atas pondasi tipis)
const SLAB_T = 0.5;             // tebal pelat lantai atas
const STAIR_RUN = 16;           // panjang satu bentang tangga (kemiringan 10/16)
const STAIR_W = 5;              // lebar satu jalur tangga (switchback = 2 jalur)
const STEP_UP = 1.2;            // tinggi maksimum yang bisa dinaiki tanpa lompat

// ===== Integrasi Modul Game (Workstream B/C/E) =====
// Mapping missionId → tipe mini-game (lihat missions-minigame.js). Default: 'wire'.
const MISSION_MINIGAME_MAP = {
  'mission-rakitan': 'assemble',
  'mission-teknologi': 'assemble',
  'mission-industri': 'assemble',
  'mission-jaringan': 'wire',
  'mission-keuangan': 'cash',
  'mission-literasi': 'sortbooks'
};
// missionIds per hotspot 3D (id hotspot → missionId padanan lokasi di locations.json).
const HOTSPOT_MISSION_IDS = {
  perpus: 'mission-literasi',
  toko: 'mission-belanja',
  futsal: 'mission-olahraga',
  lab_tik: 'mission-teknologi',
  lab_rakitan: 'mission-rakitan',
  bank_mini: 'mission-keuangan',
  ruang_tu: 'mission-layanan',
  ruang_bk: 'mission-karier',
  ruang_praktik: 'mission-industri',
  silber_ai: 'mission-industri'
};
class VirtualCampus3D {
  constructor(canvasId, options = {}) {
    this.canvas = document.getElementById(canvasId);
    if (!this.canvas) throw new Error("Canvas element not found: " + canvasId);
    this.options = Object.assign({
      campusDataUrl: '/api/campus',
      quality: 'auto', // low | medium | high | auto
      roofMode: true,
      onRoomEnter: null,
      avatarConfig: null
    }, options);

    this.scene = null;
    this.camera = null;
    this.renderer = null;
    this.campusData = null;
    this.colliders = [];
    this.surfaces = [];   // permukaan pijakan datar (lantai gedung, pelat lantai atas)
    this.ramps = [];      // tangga (ketinggian linear sepanjang sumbu)
    this.interactiveHotspots = [];
    this.doorSigns = [];
    this.windowMeshes = [];
    this.seats = [];      // kursi/bangku/sofa yang bisa diduduki ({ obj, top })

    // Avatar state & Customization
    this.player = null;
    this.avatarConfig = this.options.avatarConfig || null;
    this.remotePlayers = new Map();
    this.playerPos = new THREE.Vector3(135, 0, 12);
    this.playerVelocity = new THREE.Vector3(0, 0, 0);
    this.isGrounded = true;
    this.targetGroundY = 0;
    this.currentFloor = 1;
    this.headingAngle = Math.PI; // facing south/inwards
    this.walkAnimTime = 0;
    this.idleTime = 0;
    this.isMoving = false;
    this.isSprinting = false;

    // Quality state
    let savedQ = ''; try { savedQ = localStorage.getItem('vc_gfx_quality') || ''; } catch (e) {}
    this.currentQuality = savedQ || this.options.quality || 'auto';
    this.effectiveQuality = 'medium';

    // Camera settings & Mouse Orbit Controls
    this.cameraMode = 'third_person'; // third_person | first_person | top_down
    this.camDistance = 26;
    this.camPitch = 0.35; // radians
    this.camYaw = Math.PI; // radians (faces forwards initially)
    this.isDragging = false;
    this.prevPointer = { x: 0, y: 0 };

    // Input state
    this.keys = {};
    this.joystickVector = { x: 0, y: 0 };

    // Futsal Ball
    this.soccerBall = null;
    this.ballVelocity = new THREE.Vector3(0, 0, 0);
    this.ballKickCd = 0;

    // Emote state
    this.emoteTime = 0;

    // Fountain animation
    this.fountainWater = null;

    // Audio synthesizer
    this.audioCtx = null;
  }

  async init() {
    this.setupRenderer();
    this.setupScene();
    this.setupLighting();
    this.setupInputs();

    // Apply active quality preset
    this.setQuality(this.currentQuality, false);

    // Fetch data-driven map
    await this.loadCampusData();
    if (this.campusData && this.campusData.spawn) {
      const sp = this.campusData.spawn;
      this.playerPos.set(Number(sp.x) || 135, Number(sp.y) || 0, Number(sp.z) || 12);
    }
    this.buildWorld();
    // Siang / malam mengikuti jam perangkat (berubah otomatis saat jam berganti fase).
    try {
      this.setTimeOfDay(window.vcDaypart ? window.vcDaypart() : 'siang', true);
      window.addEventListener('vc:daypart', (e) => { if (!this.manualTime) this.setTimeOfDay(e.detail, false); });
    } catch (e) { console.warn('[3D] suasana waktu dilewati:', e); }
    try { // Spawn di DALAM ruang kelas (lantai dasar gedung spawn), titik acak di area bebas tangga,
    // agar 30 siswa yang masuk bersamaan tidak bertumpuk dan tidak muncul di atas/luar gedung.
    const sp = this.campusData && this.campusData.spawn;
    const sb = sp && sp.building && (this.campusData.buildings || []).find(b => b.id === sp.building);
    if (sb) {
      const bb = sb.bounds, alongX = bb.width >= bb.depth;
      const stairPad = (sb.floors > 1) ? STAIR_RUN + 4 : 0;
      for (let i = 0; i < 20; i++) {
        const x = alongX ? bb.x - bb.width / 2 + stairPad + 4 + Math.random() * (bb.width - stairPad - 8) : bb.x + (Math.random() - 0.5) * (bb.width - 8);
        const z = alongX ? bb.z + (Math.random() - 0.5) * (bb.depth - 8) : bb.z - bb.depth / 2 + stairPad + 4 + Math.random() * (bb.depth - stairPad - 8);
        if (!this.checkCollision(x, z)) { this.playerPos.set(x, FLOOR_BASE, z); break; }
      }
    } else {
      for (let i = 0; i < 12; i++) {
        const a = Math.random() * Math.PI * 2, r = 3 + Math.random() * 6;
        const x = this.playerPos.x + Math.cos(a) * r, z = this.playerPos.z + Math.sin(a) * r;
        if (!this.checkCollision(x, z)) { this.playerPos.x = x; this.playerPos.z = z; break; }
      }
    }
    } catch (e) { console.warn('[3D] spawn default dipakai:', e); }
    if (!this.avatarConfig && window.__AVATAR && window.__AVATAR.config) this.avatarConfig = window.__AVATAR.config; // gaya kustom v2
    this.createPlayerAvatar();
    if (!this.avatarConfig) {
      try { await this.loadActiveAvatar(); } catch (e) { /* avatar default */ }
    }

    window.addEventListener('resize', () => this.onWindowResize());

    // Karakter story (opsional, hanya penampilan) — tidak boleh menggagalkan 3D.
    try { this.__vcGameStart(); } catch (e) { console.warn('[3D] karakter dilewati:', e); }

    this.animate();
  }

  /* ===== Integrasi Modul Game (dipanggil sekali di akhir init) ===== */
  /**
   * Karakter story = cerita + desain penampilan (tanpa tugas). Dipilih di lobi
   * ("Dashboard Sebelum Bermain"), bukan di dalam dunia. Tugas datang dari guru/staf.
   */
  __vcGameStart() {
    if (window.__AVATAR && window.__AVATAR.config) { // gaya kustom dari dashboard menang atas karakter cerita
      const sid = window.__AVATAR.config.story || '';
      if (sid && window.CharacterStory) CharacterStory.load(() => { const c = CharacterStory.getById(sid); window.__vc3dCharName = c ? c.name : ''; });
      return;
    }
    if (!window.CharacterStory) return;
    CharacterStory.load(() => this.applyStoryCharacter(CharacterStory.getSelected()));
  }

  applyStoryCharacter(charId) {
    const c = (charId && window.CharacterStory) ? CharacterStory.getById(charId) : null;
    window.__vc3dCharId = c ? c.id : '';
    window.__vc3dCharName = c ? c.name : '';
    if (window.LiveChat) LiveChat.setContext({ charName: window.__vc3dCharName, locationName: this.__vcLocName || 'Sekolah' });
    if (c && c.design) {
      const d = c.design;
      const hairMap = { spiky: 'undercut', short: 'neat_short', messy: 'neat_short', long: 'ponytail', ponytail: 'ponytail', hijab: 'hijab' };
      this.avatarConfig = {
        storyChar: c.id, skin_tone: d.skin, hair_color: d.hair, hair: hairMap[d.hairStyle] || 'neat_short',
        outfit_color: d.top, pants_color: d.bottom, shoes_color: d.shoes, accent: d.accent
      };
      this.createPlayerAvatar();
      if (typeof window.sendWsMessage === 'function') window.sendWsMessage({ type: 'avatar_change', avatarConfig: this.avatarConfig });
    }
  }

  setupRenderer() {
    // Tanpa pra-cek/aturan spesifikasi: langsung coba renderer dari setelan normal sampai paling ringan.
    // (Pra-cek getContext('webgl') dulu menolak perangkat yang sebenarnya sanggup dengan setelan ringan.)

    // Coba bertahap: normal → ringan. Beberapa GPU HP lama menolak antialias/high-performance
    // padahal sanggup dengan setelan ringan; dulu langsung gagal → layar hitam.
    const isMobile = /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent) || ((navigator.maxTouchPoints || 0) > 1 && window.innerWidth < 1100);
    const attempts = [
      { antialias: !isMobile && this.options.quality !== 'low', powerPreference: isMobile ? 'default' : 'high-performance' },
      { antialias: false, powerPreference: 'default', precision: 'mediump', failIfMajorPerformanceCaveat: false },
      { antialias: false, powerPreference: 'low-power', precision: 'lowp', depth: true, stencil: false, failIfMajorPerformanceCaveat: false }
    ];
    let lastErr = null;
    for (const opt of attempts) {
      try { this.renderer = new THREE.WebGLRenderer(Object.assign({ canvas: this.canvas }, opt)); break; }
      catch (e) { lastErr = e; this.renderer = null; }
    }
    if (!this.renderer) throw (lastErr || new Error("WebGL tidak dapat dibuat pada perangkat ini."));
    this.isMobileDevice = isMobile;

    // GPU HP bisa me-reset konteks (memori penuh/aplikasi lain). Tanpa ini layar membeku diam-diam.
    this.canvas.addEventListener('webglcontextlost', (e) => {
      e.preventDefault();
      this.contextLost = true;
      if (window.UI) UI.toast('Grafis sedang dipulihkan… (memori HP penuh)', 'gold', 4000);
    }, false);
    this.canvas.addEventListener('webglcontextrestored', () => {
      this.contextLost = false;
      // Turunkan kualitas agar tidak terulang, lalu muat ulang halaman supaya semua tekstur dibangun lagi.
      try { localStorage.setItem('vc_gfx_quality', 'low'); } catch (e) {}
      setTimeout(() => location.reload(), 300);
    }, false);

    const dpr = (this.options.quality === 'high') ? Math.min(window.devicePixelRatio || 1, 2) : 1;
    this.renderer.setPixelRatio(dpr);
    this.renderer.setSize(window.innerWidth, window.innerHeight);
    this.renderer.shadowMap.enabled = (this.options.quality !== 'low');
    if (this.renderer.shadowMap.enabled) {
      this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    }

    // Tone mapping & color pipeline: rolloff highlight terkendali, tidak memperberat GPU.
    // Pass sinematik HD-2D: ACESFilmic + exposure hangat (Workstream D).
    // NOTE: Three.js postprocessing (EffectComposer/UnrealBloomPass) TIDAK tersedia di
    // bundle self-hosted (assets/vendor/three.min.js) → bloom tidak ditambahkan
    // (tanpa dependency baru); andalkan tone mapping + warm lighting + fog lembut.
    this.renderer.outputEncoding = THREE.sRGBEncoding;
    // Gaya toon/cel-shading: ACES membuat warna kartun pucat → tone mapping linear.
    this.renderer.toneMapping = THREE.LinearToneMapping;
    this.renderer.toneMappingExposure = 0.92;
  }

  setQuality(mode, showToast = true) {
    if (!['low', 'medium', 'high', 'auto'].includes(mode)) mode = 'auto';
    this.currentQuality = mode;

    let eff = mode;
    if (mode === 'auto') {
      const isMobile = /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent) || window.innerWidth < 900;
      const cores = navigator.hardwareConcurrency || 4;
      const mem = navigator.deviceMemory || 4; // GB (Chrome/Android); undefined di iOS → anggap 4
      if (mem <= 2) {
        eff = 'low';                             // HP/laptop RAM kecil
      } else if (isMobile) {
        // HP kelas menengah ke bawah: tanpa bayangan supaya tetap 60 FPS & hemat baterai.
        eff = cores <= 4 ? 'low' : 'medium';
      } else {
        eff = cores <= 4 ? 'medium' : 'high';
      }
    }
    this.effectiveQuality = eff;

    if (this.renderer) {
      const isSmall = /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent) || window.innerWidth < 900;
      let dpr = (eff === 'high') ? Math.min(window.devicePixelRatio || 1, 2) : (eff === 'medium' ? 1.25 : 1.0);
      if (isSmall) dpr = Math.min(dpr, 1.0); // hemat GPU di HP
      this.renderer.setPixelRatio(dpr);
      this.renderer.shadowMap.enabled = (eff !== 'low');
      if (this.renderer.shadowMap.enabled) {
        this.renderer.shadowMap.type = (eff === 'high') ? THREE.PCFSoftShadowMap : THREE.BasicShadowMap;
      }
    }

    if (this.sunLight) {
      if (eff === 'low') {
        this.sunLight.castShadow = false;
      } else {
        this.sunLight.castShadow = true;
        const sz = (eff === 'high') ? 2048 : 1024;
        this.sunLight.shadow.mapSize.width = sz;
        this.sunLight.shadow.mapSize.height = sz;
      }
    }

    if (this.scene && this.scene.fog) {
      this.scene.fog.density = (eff === 'low') ? 0.0032 : (eff === 'medium' ? 0.0027 : 0.0024);
    }

    if (this.camera) {
      this.camera.far = (eff === 'low') ? 750 : (eff === 'medium' ? 1200 : 1600);
      this.camera.updateProjectionMatrix();
    }

    const qBtn = document.getElementById('qualityToggleBtn');
    if (qBtn) {
      const labels = { low: '⚡ Rendah', medium: '⚖️ Sedang', high: '✨ Tinggi', auto: '⚙️ Auto' };
      qBtn.textContent = 'Grafis: ' + (labels[mode] || mode);
    }

    if (showToast && window.UI) {
      UI.toast('Kualitas grafis diatur ke: ' + mode.toUpperCase(), 'info', 1600);
    }
  }

  /**
   * Kualitas adaptif: ukur FPS nyata tiap 4 detik. <24 fps → turun ke 'low';
   * masih <18 fps di 'low' → resolusi render 75%. Pilihan disimpan per perangkat.
   */
  watchPerformance(now) {
    if (!this.__perf) this.__perf = { t0: now, frames: 0, downgraded: false, scaled: false };
    const p = this.__perf;
    p.frames++;
    const dt = now - p.t0;
    if (dt < 4000) return;
    const fps = p.frames * 1000 / dt;
    p.t0 = now; p.frames = 0;
    this.fps = Math.round(fps);
    if (document.hidden) return;
    if (fps < 24 && this.effectiveQuality !== 'low' && !p.downgraded) {
      p.downgraded = true;
      this.setQuality('low', false);
      try { localStorage.setItem('vc_gfx_quality', 'low'); } catch (e) {}
      if (window.UI) UI.toast('Grafis diringankan otomatis agar lancar di perangkat ini ⚡', 'info', 2500);
    } else if (fps < 18 && this.effectiveQuality === 'low' && !p.scaled && this.renderer) {
      p.scaled = true;
      this.renderer.setPixelRatio(0.75);
      this.renderer.setSize(window.innerWidth, window.innerHeight);
    }
  }

  toggleQuality() {
    const sequence = ['auto', 'high', 'medium', 'low'];
    const currIdx = sequence.indexOf(this.currentQuality || 'auto');
    const nextMode = sequence[(currIdx + 1) % sequence.length];
    this.setQuality(nextMode, true);
    try { localStorage.setItem('vc_gfx_quality', nextMode); } catch (e) {}
    if (this.__perf) { this.__perf.downgraded = true; this.__perf.scaled = true; } // hormati pilihan manual
  }

  setupScene() {
    this.scene = new THREE.Scene();
    this.isNight = false;

    // Soft, cozy, eye-friendly pastel sky & fog palette
    this.skyColorDay = new THREE.Color(0x7dd3fc); // Soft sky blue
    this.skyColorNight = new THREE.Color(0x0b1329); // Deep aesthetic midnight navy
    this.fogColorDay = new THREE.Color(0xcfe6f7); // = warna horizon langit → tidak ada garis horizon keras
    this.fogColorNight = new THREE.Color(0x111827); // Soft twilight fog

    // Langit gradasi (biru tua di atas → kabut horizon). Satu CanvasTexture 2×256, nyaris gratis.
    this.skyTexDay = this.makeSkyTexture('#3b82f6', '#93c5fd', '#cfe6f7');
    this.skyTexNight = this.makeSkyTexture('#020617', '#0b1329', '#111827');
    this.scene.background = this.skyTexDay;
    this.scene.fog = new THREE.FogExp2(this.fogColorDay.clone(), 0.0024);

    this.camera = new THREE.PerspectiveCamera(54, window.innerWidth / window.innerHeight, 0.12, 1600); // near kecil: tangan POV
  }

  makeSkyTexture(top, mid, horizon) {
    const c = document.createElement('canvas');
    c.width = 2; c.height = 256;
    const g = c.getContext('2d');
    const grad = g.createLinearGradient(0, 0, 0, 256);
    grad.addColorStop(0, top); grad.addColorStop(0.55, mid); grad.addColorStop(1, horizon);
    g.fillStyle = grad; g.fillRect(0, 0, 2, 256);
    const tex = new THREE.CanvasTexture(c);
    tex.encoding = THREE.sRGBEncoding;
    return tex;
  }

  /** Matahari & frustum bayangan ikut pemain → bayangan tajam di sekitar pemain (shadow map sama). */
  updateSunFollow() {
    if (!this.sunLight || !this.playerPos) return;
    const o = this.sunOffset;
    this.sunLight.position.set(this.playerPos.x + o.x, this.playerPos.y + o.y, this.playerPos.z + o.z);
    this.sunLight.target.position.copy(this.playerPos);
    this.sunLight.target.updateMatrixWorld();
  }

  setupLighting() {
    // Ambient / Hemisphere Sky light (soft sky bounce + grass bounce)
    this.hemiLight = new THREE.HemisphereLight(0xdbeafe, 0x6b8f6b, 0.62);
    this.hemiLight.position.set(0, 160, 0);
    this.scene.add(this.hemiLight);

    // Directional Sunlight with warm soft tone
    this.sunLight = new THREE.DirectionalLight(0xfff1d6, 1.2);
    this.sunOffset = new THREE.Vector3(70, 120, 50);
    this.sunLight.position.set(130, 220, 90);
    this.scene.add(this.sunLight.target);
    if (this.options.quality !== 'low') {
      this.sunLight.castShadow = true;
      this.sunLight.shadow.mapSize.width = 2048;
      this.sunLight.shadow.mapSize.height = 2048;
      this.sunLight.shadow.camera.near = 10;
      this.sunLight.shadow.camera.far = 400;
      const d = 110; // area bayangan 220×220 di sekitar pemain (dulu 520×520 statis → buram)
      this.sunLight.shadow.camera.left = -d;
      this.sunLight.shadow.camera.right = d;
      this.sunLight.shadow.camera.top = d;
      this.sunLight.shadow.camera.bottom = -d;
      this.sunLight.shadow.bias = -0.0003;
    }
    this.scene.add(this.sunLight);

    // Night Street Lamps
    this.streetLamps = [];
    this.buildCampusStreetLamps();
  }

  buildCampusStreetLamps() {
    const lampPositions = [
      [40, 20], [-40, 20], [40, -40], [-40, -40],
      [100, 20], [100, -20], [-100, 20], [-100, -20],
      [0, 50], [0, -50], [140, 0], [-140, 0],
      [60, 60], [-60, 60], [60, -60], [-60, -60],
      [145, -70], [145, -35], [-75, -85], [-75, -50],
      [0, -130], [0, 130], [-160, 15], [140, 15]
    ];

    lampPositions.forEach(([lx, lz]) => {
      const lampGroup = new THREE.Group();
      lampGroup.position.set(lx, 0, lz);

      // Stylized Lamp Base & Post
      const poleGeo = new THREE.CylinderGeometry(0.35, 0.5, 12, 8);
      const poleMat = new THREE.MeshLambertMaterial({ color: 0x334155 });
      const pole = new THREE.Mesh(poleGeo, poleMat);
      pole.position.y = 6;
      pole.castShadow = true;
      lampGroup.add(pole);

      // Modern Lantern Arm & Fixture
      const armGeo = new THREE.BoxGeometry(2.4, 0.4, 0.4);
      const arm = new THREE.Mesh(armGeo, poleMat);
      arm.position.set(0.6, 12, 0);
      lampGroup.add(arm);

      // Lantern Glass / Bulb
      const lanternGeo = new THREE.SphereGeometry(1.0, 12, 12);
      const lanternMat = new THREE.MeshLambertMaterial({
        color: 0xfffbeb,
        emissive: 0x000000
      });
      const lantern = new THREE.Mesh(lanternGeo, lanternMat);
      lantern.position.set(1.4, 11.6, 0);
      lampGroup.add(lantern);

      // Tanpa PointLight: di Three r128 setiap lampu (meski intensitas 0) dihitung di shader
      // semua material. Malam cukup lentera emissive. Objek ringan agar kode lama tetap jalan.
      const pLight = { intensity: 0 };

      this.scene.add(lampGroup);
      this.streetLamps.push({ lantern, pLight });
    });
  }

  /**
   * Suasana waktu mengikuti jam perangkat (pixel-shell.js → window.vcDaypart / event 'vc:daypart'):
   * 'pagi' | 'siang' | 'sore' | 'malam'. Tombol ☀️/🌙 di HUD tetap bisa mengganti manual.
   */
  setTimeOfDay(part, silent) {
    const P = TIME_PRESETS[part] ? part : 'siang';
    const t = TIME_PRESETS[P];
    this.timeOfDay = P;
    this.isNight = P === 'malam';
    if (!this.skyTexByPart) this.skyTexByPart = { siang: this.skyTexDay, malam: this.skyTexNight };
    if (!this.skyTexByPart[P]) this.skyTexByPart[P] = this.makeSkyTexture(t.sky[0], t.sky[1], t.sky[2]);
    this.scene.background = this.skyTexByPart[P];
    this.scene.fog.color.setHex(t.fog);
    this.hemiLight.color.setHex(t.hemi[0]);
    this.hemiLight.groundColor.setHex(t.hemi[1]);
    this.hemiLight.intensity = t.hemi[2];
    this.sunLight.color.setHex(t.sun[0]);
    this.sunLight.intensity = t.sun[1];
    this.sunOffset.set(t.sunOffset[0], t.sunOffset[1], t.sunOffset[2]);

    // Lampu jalan & jendela menyala saat malam (emissive saja, tanpa PointLight → ringan)
    this.streetLamps.forEach(l => {
      l.pLight.intensity = t.lamps ? 1.8 : 0;
      if (l.lantern.material.emissive) l.lantern.material.emissive.setHex(t.lamps ? 0xfef08a : 0x000000);
    });
    this.windowMeshes.forEach(wm => {
      wm.material.color.setHex(t.lamps ? 0xfef08a : 0x38bdf8);
      if (wm.material.emissive) wm.material.emissive.setHex(t.lamps ? 0xd97706 : 0x000000);
    });

    const btn = document.getElementById('dayNightBtn');
    if (btn) btn.innerHTML = t.label;
    if (!silent && window.UI) UI.toast('Suasana ' + t.label, 'info');
  }

  toggleDayNight() {
    this.manualTime = true; // pilihan manual dihormati sampai halaman dimuat ulang
    this.setTimeOfDay(this.isNight ? 'siang' : 'malam', false);
  }

  async loadCampusData() {
    try {
      const res = await fetch(this.options.campusDataUrl);
      this.campusData = await res.json();
    } catch (e) {
      console.warn("Gagal fetch campusData via URL, fallback ke data bawaan.", e);
      this.campusData = { buildings: [], rooms: [], stairs: [] };
    }
  }

  /**
   * Tekstur prosedural ringan (CanvasTexture 256px, diulang) — memberi detail permukaan
   * tanpa file gambar tambahan. kind: 'paving' | 'grass' | 'tile'.
   */
  makeSurfaceTexture(kind, repeatX, repeatY) {
    const key = kind + ':' + repeatX + 'x' + repeatY;
    this.__texCache = this.__texCache || {};
    if (this.__texCache[key]) return this.__texCache[key];
    const c = document.createElement('canvas');
    c.width = c.height = 256;
    const g = c.getContext('2d');
    let seed = 7;
    const rnd = () => { seed = (seed * 16807) % 2147483647; return seed / 2147483647; };
    if (kind === 'grass') {
      g.fillStyle = '#4f9a44'; g.fillRect(0, 0, 256, 256);
      for (let i = 0; i < 2600; i++) {
        const v = rnd();
        g.fillStyle = v < 0.5 ? 'rgba(39,110,44,0.35)' : 'rgba(132,190,92,0.30)';
        g.fillRect(rnd() * 256, rnd() * 256, 2, 3 + rnd() * 3);
      }
    } else {
      const paving = kind === 'paving';
      const n = paving ? 4 : 2;          // ubin per sisi tekstur
      const cell = 256 / n;
      g.fillStyle = paving ? '#a39c90' : '#b9b2a6'; g.fillRect(0, 0, 256, 256); // nat
      for (let y = 0; y < n; y++) for (let x = 0; x < n; x++) {
        const off = (paving && y % 2) ? cell / 2 : 0;   // pola bata selang-seling
        const l = paving ? 74 + rnd() * 8 : 88 + rnd() * 4;
        g.fillStyle = paving ? `hsl(36, 10%, ${l}%)` : `hsl(30, 14%, ${l}%)`;
        g.fillRect(x * cell + off + 2, y * cell + 2, cell - 4, cell - 4);
        if (off) g.fillRect(x * cell + off + 2 - 256, y * cell + 2, cell - 4, cell - 4);
      }
    }
    const tex = new THREE.CanvasTexture(c);
    tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
    tex.repeat.set(repeatX, repeatY);
    tex.encoding = THREE.sRGBEncoding;
    tex.anisotropy = 4;
    this.__texCache[key] = tex;
    return tex;
  }

  buildWorld() {
    // 1. Lush Campus Lawn (Enlarged to 1200x1200 with soft natural grass)
    const groundGeo = new THREE.PlaneGeometry(1200, 1200);
    // Hijau neon polos (0x22c55e) → rumput bertekstur natural.
    const groundMat = new THREE.MeshLambertMaterial({ color: 0xffffff, map: this.makeSurfaceTexture('grass', 120, 120) });
    const ground = new THREE.Mesh(groundGeo, groundMat);
    ground.rotation.x = -Math.PI / 2;
    ground.position.y = -0.05;
    ground.receiveShadow = true;
    this.scene.add(ground);

    // Outer Campus Ring Road / Asphalt Perimeter Track
    const roadGeo = new THREE.RingGeometry(240, 275, 36);
    const roadMat = new THREE.MeshLambertMaterial({ color: 0x334155 });
    const roadMesh = new THREE.Mesh(roadGeo, roadMat);
    roadMesh.rotation.x = -Math.PI / 2;
    roadMesh.position.set(0, 0.005, 15);
    roadMesh.receiveShadow = true;
    this.scene.add(roadMesh);

    // 2. Inner Campus Plaza & Main Stone Paving (Enlarged to 460 x 280)
    const plazaGeo = new THREE.PlaneGeometry(460, 280);
    // Plaza 460×280 dulu putih polos (0xe2e8f0) dan mendominasi layar → paving batu hangat.
    const plazaMat = new THREE.MeshLambertMaterial({ color: 0xffffff, map: this.makeSurfaceTexture('paving', 58, 35) });
    const plaza = new THREE.Mesh(plazaGeo, plazaMat);
    plaza.rotation.x = -Math.PI / 2;
    plaza.position.set(0, 0.01, 15);
    plaza.receiveShadow = true;
    this.scene.add(plaza);

    // Plaza Border Curb (Dark Slate Trim)
    const curbGeo = new THREE.RingGeometry(180, 185, 4);
    const curbMat = new THREE.MeshLambertMaterial({ color: 0x94a3b8 });
    const curb = new THREE.Mesh(curbGeo, curbMat);
    curb.rotation.x = -Math.PI / 2;
    curb.position.set(0, 0.02, 15);
    this.scene.add(curb);

    // Pedestrian Walkways connecting areas across enlarged campus
    const walkways = [
      { x: 0, z: -130, w: 18, d: 150 }, // Path to North buildings & outdoor park
      { x: 0, z: 130, w: 20, d: 110 },  // Path to South entrance boulevard
      { x: -160, z: 15, w: 130, d: 16 }, // Path to West wings (Gedung C, D, Musholah)
      { x: 140, z: 15, w: 120, d: 16 },  // Path to Sports area & East Gate
      { x: 145, z: -40, w: 16, d: 90 },  // Path to Student Parking Area
      { x: -75, z: -60, w: 16, d: 70 },  // Path to Outdoor Cafeteria & Garden
    ];
    walkways.forEach(wk => {
      const wGeo = new THREE.PlaneGeometry(wk.w, wk.d);
      const wMat = new THREE.MeshLambertMaterial({ color: 0xcbd5e1 });
      const wMesh = new THREE.Mesh(wGeo, wMat);
      wMesh.rotation.x = -Math.PI / 2;
      wMesh.position.set(wk.x, 0.02, wk.z);
      wMesh.receiveShadow = true;
      this.scene.add(wMesh);
    });

    // 3. Build Buildings with 3D Architectural Windows & Details
    if (this.campusData && this.campusData.buildings) {
      this.campusData.buildings.forEach(b => this.createBuildingMesh(b));
    }

    // 4. (Tangga luar lama menuju "balkon LT 2" palsu dihapus — tangga kini di dalam tiap gedung bertingkat.)

    // 5. Pagar keliling sekolah + gerbang utama terbuka (timur, sejajar Pos Keamanan) & celah pejalan kaki.
    this.safeStep('buildPerimeterFence', () => this.buildPerimeterFence());


    // 5. Build Futsal Court & Ball
    this.safeStep('buildFutsalField', () => this.buildFutsalField());

    // 6. Build Courtyard Garden (Fountain, Benches, Flagpole & Trees)
    this.safeStep('buildGarden', () => this.buildGarden());

    // 7. Build Student Motorcycle Parking Zone (Parkiran Siswa SMK Silber)
    this.safeStep('buildParkingZone', () => this.buildParkingZone());

    // 8. Build Outdoor Cafeteria & Relaxation Gazebo
    this.safeStep('buildOutdoorCafeteria', () => this.buildOutdoorCafeteria());

    // 9. Build Campus Perimeter Boundary & Gate Pillars
    this.safeStep('buildCampusPerimeter', () => this.buildCampusPerimeter());

    // 10. Build Interactive Campus Hotspots & Beacons
    this.safeStep('buildInteractiveHotspots', () => this.buildInteractiveHotspots());

    // 11. Gaya toon untuk SELURUH dunia (di akhir agar semua objek ikut) + garis tepi gedung.
    this.safeStep('toonify', () => this.toonify(this.scene));
    this.safeStep('edgeOutlines', () => this.addEdgeOutlines(this.scene));
  }

  /**
   * Gedung (1 lantai atau bertingkat). Gedung bertingkat dibangun PER LANTAI (dinding, pelat,
   * perabot, tangga switchback di sisi kiri-belakang) agar lantai di atas pemain bisa
   * disembunyikan saat berada di dalam, dan collider dinding punya rentang ketinggian.
   * Permukaan pijakan (lantai/pelat) & tangga (ramp) didaftarkan ke this.surfaces/this.ramps.
   */
  createBuildingMesh(b) {
    const group = new THREE.Group();
    const bx = b.bounds.x, bz = b.bounds.z;
    group.position.set(bx, 0, bz);
    const w = b.bounds.width;
    const d = b.bounds.depth;
    const floors = Math.max(1, parseInt(b.floors, 10) || 1);
    const multi = floors > 1;
    const h = multi ? floors * FLOOR_H : b.bounds.height;
    const wallColor = parseInt(b.wallColor.replace('#', '0x')) || 0xf8fafc;
    const roofColor = parseInt(b.roofColor.replace('#', '0x')) || 0x1e3a8a;
    const wallThick = 0.8;
    const wallMat = new THREE.MeshLambertMaterial({ color: wallColor });
    const doorW = Math.min(8.0, w * 0.42);
    const doorHalf = doorW / 2;
    const doorH = Math.min(7.5, (multi ? FLOOR_H : h) * 0.6);
    b.levelGroups = [];
    b.floors = floors;

    // 1. Pondasi TIPIS (dulu kotak padat 1.4 unit menelan lantai & perabot yang ditaruh di y=0.2).
    const baseMesh = new THREE.Mesh(new THREE.BoxGeometry(w + 1.2, FLOOR_BASE, d + 1.2), new THREE.MeshLambertMaterial({ color: 0x334155 }));
    baseMesh.position.y = FLOOR_BASE / 2;
    baseMesh.receiveShadow = true;
    group.add(baseMesh);

    let floorMatColor = 0xf1f5f9;
    if (b.type === 'prayer') floorMatColor = 0x15803d;
    else if (b.type === 'library') floorMatColor = 0xb45309;
    else if (b.type === 'bank') floorMatColor = 0x94a3b8;
    else if (b.type === 'market') floorMatColor = 0xe2e8f0;
    else if (b.type === 'lab' || b.type === 'workshop') floorMatColor = 0x334155;
    const floorMatFor = (fw, fd) => floorMatColor === 0xf1f5f9
      ? new THREE.MeshLambertMaterial({ color: 0xffffff, map: this.makeSurfaceTexture('tile', Math.max(1, Math.round(fw / 8)), Math.max(1, Math.round(fd / 8))) })
      : new THREE.MeshLambertMaterial({ color: floorMatColor });

    const floorMesh = new THREE.Mesh(new THREE.PlaneGeometry(w - 1.2, d - 1.2), floorMatFor(w, d));
    floorMesh.rotation.x = -Math.PI / 2;
    floorMesh.position.y = FLOOR_BASE + 0.01;
    floorMesh.receiveShadow = true;
    group.add(floorMesh);
    this.addSurface(bx - w / 2 + wallThick, bx + w / 2 - wallThick, bz - d / 2 + wallThick, bz + d / 2 - wallThick, FLOOR_BASE);

    // Kerangka tangga switchback (koordinat lokal). u = sumbu memanjang gedung, v = melintang.
    const alongX = w >= d;
    const uHalf = (alongX ? w : d) / 2, vHalf = (alongX ? d : w) / 2;
    const u0 = -uHalf + wallThick + 2.4;                   // awal bentang; > bantalan collider dinding (0.8+1.1)
    const v0 = -vHalf + wallThick + 0.2;                   // jalur pertama menempel dinding belakang
    const lane = (k) => ({ v1: v0 + (k % 2) * STAIR_W, v2: v0 + (k % 2) * STAIR_W + STAIR_W });
    const toXZ = (u, v) => alongX ? [u, v] : [v, u];
    const rectLocal = (ua, ub, va, vb) => { const [x1, z1] = toXZ(Math.min(ua, ub), Math.min(va, vb)); const [x2, z2] = toXZ(Math.max(ua, ub), Math.max(va, vb)); return [Math.min(x1, x2), Math.max(x1, x2), Math.min(z1, z2), Math.max(z1, z2)]; };
    const stairUEnd = u0 + STAIR_RUN;
    // Ruang perabot menghindari area tangga: geser sepanjang u.
    const furnShift = multi ? (STAIR_RUN + 6) / 2 : 0;
    const furnW = alongX ? w - furnShift * 2 : w;
    const furnD = alongX ? d : d - furnShift * 2;

    for (let k = 0; k < floors; k++) {
      const lg = new THREE.Group();
      lg.userData.level = k;
      group.add(lg);
      b.levelGroups.push(lg);
      const y0 = k * FLOOR_H;
      const wallH = multi ? FLOOR_H : h;
      const floorTop = k === 0 ? FLOOR_BASE : y0 + SLAB_T / 2;

      // Dinding lantai ini
      const addWall = (sx, sy, sz, px, pz) => {
        const m = new THREE.Mesh(new THREE.BoxGeometry(sx, sy, sz), wallMat);
        m.position.set(px, y0 + sy / 2, pz);
        m.castShadow = true; m.receiveShadow = true;
        m.userData.outline = true;
        lg.add(m);
        this.colliders.push({ minX: bx + px - sx / 2, maxX: bx + px + sx / 2, minZ: bz + pz - sz / 2, maxZ: bz + pz + sz / 2, minY: y0, maxY: y0 + sy, height: y0 + sy });
      };
      addWall(wallThick, wallH, d, -w / 2 + wallThick / 2, 0);
      addWall(wallThick, wallH, d, w / 2 - wallThick / 2, 0);
      addWall(w, wallH, wallThick, 0, -d / 2 + wallThick / 2);
      if (k === 0) {
        const sideW = (w - doorW) / 2;
        addWall(sideW, wallH, wallThick, -w / 2 + sideW / 2, d / 2 - wallThick / 2);
        addWall(sideW, wallH, wallThick, w / 2 - sideW / 2, d / 2 - wallThick / 2);
        const lintelH = wallH - doorH;
        if (lintelH > 0.5) {
          const lintel = new THREE.Mesh(new THREE.BoxGeometry(doorW, lintelH, wallThick), wallMat);
          lintel.position.set(0, doorH + lintelH / 2, d / 2 - wallThick / 2);
          lintel.castShadow = true;
          lg.add(lintel);
        }
      } else {
        addWall(w, wallH, wallThick, 0, d / 2 - wallThick / 2);
      }

      // Pelat lantai (k ≥ 1) berlubang di atas jalur tangga yang naik dari lantai bawahnya.
      if (k >= 1) {
        const hole = lane(k - 1);
        const slabMat = floorMatFor(w, d);
        // Lubang hanya sepanjang anak tangga [u0, stairUEnd]; strip antara dinding & u0 = bordes pijakan.
        const pieces = [
          rectLocal(stairUEnd, uHalf - wallThick, -vHalf + wallThick, vHalf - wallThick),       // seluruh sisi kanan tangga
          rectLocal(-uHalf + wallThick, u0, -vHalf + wallThick, vHalf - wallThick),             // bordes dekat dinding
          rectLocal(u0, stairUEnd, hole.v2, vHalf - wallThick),                                  // depan lubang
          rectLocal(u0, stairUEnd, -vHalf + wallThick, hole.v1)                                  // belakang lubang (bisa kosong)
        ];
        pieces.forEach(([x1, x2, z1, z2]) => {
          if (x2 - x1 < 0.2 || z2 - z1 < 0.2) return;
          const slab = new THREE.Mesh(new THREE.BoxGeometry(x2 - x1, SLAB_T, z2 - z1), slabMat);
          slab.position.set((x1 + x2) / 2, y0, (z1 + z2) / 2);
          slab.receiveShadow = true; slab.castShadow = true;
          lg.add(slab);
          this.addSurface(bx + x1, bx + x2, bz + z1, bz + z2, floorTop);
        });
        // Pagar pengaman di tepi lubang tangga (sisi yang menghadap ruangan)
        const [rx1, rx2, rz1, rz2] = rectLocal(u0 + 2, stairUEnd, hole.v2 - 0.2, hole.v2);
        const rail = new THREE.Mesh(new THREE.BoxGeometry(Math.max(0.2, rx2 - rx1), 2.2, Math.max(0.2, rz2 - rz1)), new THREE.MeshLambertMaterial({ color: 0xf59e0b }));
        rail.position.set((rx1 + rx2) / 2, floorTop + 1.1, (rz1 + rz2) / 2);
        lg.add(rail);
      }

      // Tangga naik ke lantai berikutnya (jalur selang-seling, arah bolak-balik).
      if (k < floors - 1) {
        const ln = lane(k);
        const up = (k % 2 === 0);                       // jalur genap naik ke +u, ganjil ke −u
        const ua = up ? u0 : stairUEnd, ub = up ? stairUEnd : u0;
        const yA = floorTop, yB = (k + 1) * FLOOR_H + SLAB_T / 2;
        const steps = 14;
        const stepMat = new THREE.MeshLambertMaterial({ color: 0x64748b });
        for (let st = 0; st < steps; st++) {
          const t0 = st / steps, t1 = (st + 1) / steps;
          const sh = yA + (yB - yA) * t1;
          const [x1, x2, z1, z2] = rectLocal(ua + (ub - ua) * t0, ua + (ub - ua) * t1, ln.v1, ln.v2);
          const step = new THREE.Mesh(new THREE.BoxGeometry(x2 - x1, sh - y0, z2 - z1), stepMat);
          step.position.set((x1 + x2) / 2, y0 + (sh - y0) / 2, (z1 + z2) / 2);
          step.castShadow = true; step.receiveShadow = true;
          lg.add(step);
        }
        const [x1, x2, z1, z2] = rectLocal(u0, stairUEnd, ln.v1, ln.v2);
        const [ax] = toXZ(ua, 0), [bxL] = toXZ(ub, 0);
        const [, az] = toXZ(ua, 0), [, bzL] = toXZ(ub, 0);
        this.ramps.push({ minX: bx + x1, maxX: bx + x2, minZ: bz + z1, maxZ: bz + z2,
          axis: alongX ? 'x' : 'z', a: alongX ? bx + ax : bz + az, b: alongX ? bx + bxL : bz + bzL, ya: yA, yb: yB });
      }

      // Perabot lantai ini, diangkat ke permukaan lantai & digeser menjauhi tangga.
      const furn = new THREE.Group();
      const [fx, fz] = toXZ(furnShift, 0);
      furn.position.set(fx, floorTop - 0.2, fz);
      lg.add(furn);
      if (k === 0) this.buildBuildingInterior(furn, b, furnW, wallH, furnD);
      else this.buildBuildingInterior(furn, { id: 'kelas-' + b.id + '-' + k, type: 'academic' }, furnW, wallH, furnD);

      // Jendela fasad lantai ini
      this.addBuildingWindows(lg, w, wallH, d, false, y0);
    }

    // Kanopi pintu masuk & keset (lantai dasar)
    const pillarMat = new THREE.MeshLambertMaterial({ color: 0x1e293b });
    const pLeft = new THREE.Mesh(new THREE.BoxGeometry(0.8, doorH, 1.2), pillarMat);
    pLeft.position.set(-doorHalf, doorH / 2, d / 2);
    const pRight = pLeft.clone();
    pRight.position.x = doorHalf;
    group.add(pLeft, pRight);
    const awning = new THREE.Mesh(new THREE.BoxGeometry(doorW + 2.4, 0.6, 5.0), new THREE.MeshLambertMaterial({ color: 0x0284c7 }));
    awning.position.set(0, doorH + 0.3, d / 2 + 2.2);
    awning.castShadow = true;
    group.add(awning);
    const matMesh = new THREE.Mesh(new THREE.PlaneGeometry(doorW - 0.4, 3.0), new THREE.MeshLambertMaterial({ color: 0x0284c7 }));
    matMesh.rotation.x = -Math.PI / 2;
    matMesh.position.set(0, FLOOR_BASE + 0.02, d / 2 + 0.2);
    group.add(matMesh);

    // Atap (disembunyikan saat pemain di dalam)
    const roofGroup = new THREE.Group();
    roofGroup.name = "roof";
    const roofMesh = new THREE.Mesh(new THREE.BoxGeometry(w + 3.0, 1.8, d + 3.0), new THREE.MeshLambertMaterial({ color: roofColor }));
    roofMesh.position.y = h + 0.9;
    roofMesh.castShadow = true;
    roofMesh.userData.outline = true;
    roofGroup.add(roofMesh);
    const cornice = new THREE.Mesh(new THREE.BoxGeometry(w + 3.8, 0.4, d + 3.8), new THREE.MeshLambertMaterial({ color: 0x0f172a }));
    cornice.position.y = h + 1.9;
    roofGroup.add(cornice);
    if (b.hasDome) {
      const domeMesh = new THREE.Mesh(new THREE.SphereGeometry(6.2, 24, 16, 0, Math.PI * 2, 0, Math.PI / 2), new THREE.MeshLambertMaterial({ color: 0x16a34a }));
      domeMesh.position.y = h + 2.0;
      roofGroup.add(domeMesh);
      const spire = new THREE.Mesh(new THREE.CylinderGeometry(0.2, 0.5, 4.0, 8), new THREE.MeshLambertMaterial({ color: 0xf59e0b }));
      spire.position.y = h + 8.5;
      roofGroup.add(spire);
    }
    group.add(roofGroup);
    b.roofGroup = roofGroup;

    const signMesh = this.createSignCanvas(b.name, Math.min(w * 0.75, 26), 4.6);
    signMesh.position.set(0, (multi ? doorH + 3.2 : h + 3.8), d / 2 + (multi ? 0.6 : 0.4));
    group.add(signMesh);

    this.scene.add(group);
  }

  /**
   * Pagar besi keliling (persegi, di dalam ring road) dengan gerbang utama TERBUKA di sisi timur
   * dan celah pejalan kaki di sisi lain. Tiang & jeruji = InstancedMesh (1 draw call per jenis).
   * Pagar ber-collider (tak bisa ditembus), gerbang & celah bisa dilewati.
   */
  buildPerimeterFence() {
    const X0 = -203, X1 = 198, Z0 = -106, Z1 = 122, H = 5.0;
    const GATE = { side: 'E', at: 12, w: 26 };                 // gerbang utama (gate-main z=12)
    const gaps = [GATE, { side: 'N', at: 0, w: 12 }, { side: 'W', at: 15, w: 12 }, { side: 'S', at: 145, w: 16 }, { side: 'S', at: -75, w: 12 }];
    // 4 sisi sebagai garis lurus: [x1,z1,x2,z2,side]
    const sides = [[X0, Z0, X1, Z0, 'S'], [X0, Z1, X1, Z1, 'N'], [X0, Z0, X0, Z1, 'W'], [X1, Z0, X1, Z1, 'E']];
    const runs = [];
    sides.forEach(([x1, z1, x2, z2, side]) => {
      const alongX = z1 === z2;
      const a = alongX ? x1 : z1, b = alongX ? x2 : z2;
      const cuts = gaps.filter(g => g.side === side).map(g => [g.at - g.w / 2, g.at + g.w / 2]).sort((p, q) => p[0] - q[0]);
      let cur = a;
      cuts.forEach(([c1, c2]) => { if (c1 > cur) runs.push({ alongX, fixed: alongX ? z1 : x1, a: cur, b: c1 }); cur = Math.max(cur, c2); });
      if (b > cur) runs.push({ alongX, fixed: alongX ? z1 : x1, a: cur, b });
    });

    const postMat = new THREE.MeshLambertMaterial({ color: 0x334155 });
    const barMat = new THREE.MeshLambertMaterial({ color: 0x1e3a8a });
    const railMat = new THREE.MeshLambertMaterial({ color: 0xf59e0b });
    const postPts = [], barPts = [];
    const fence = new THREE.Group();
    runs.forEach(r => {
      const len = r.b - r.a;
      const at = (t) => r.alongX ? [t, r.fixed] : [r.fixed, t];
      for (let t = r.a; t <= r.b + 0.01; t += Math.max(4, len / Math.max(1, Math.round(len / 8)))) postPts.push(at(t));
      for (let t = r.a + 0.8; t < r.b - 0.4; t += 1.2) barPts.push(at(t));
      [1.0, H - 0.6].forEach(ry => {                           // dua rel horizontal per bentang
        const rail = new THREE.Mesh(new THREE.BoxGeometry(r.alongX ? len : 0.25, 0.3, r.alongX ? 0.25 : len), railMat);
        const [cx, cz] = at((r.a + r.b) / 2);
        rail.position.set(cx, ry, cz);
        fence.add(rail);
      });
      const [x1, z1] = at(r.a), [x2, z2] = at(r.b);
      this.colliders.push({ minX: Math.min(x1, x2) - 0.3, maxX: Math.max(x1, x2) + 0.3, minZ: Math.min(z1, z2) - 0.3, maxZ: Math.max(z1, z2) + 0.3, minY: 0, maxY: H, height: H });
    });
    const inst = (geo, mat, pts, y) => {
      const im = new THREE.InstancedMesh(geo, mat, pts.length);
      const m = new THREE.Matrix4();
      pts.forEach(([x, z], i) => { m.makeTranslation(x, y, z); im.setMatrixAt(i, m); });
      im.castShadow = true;
      fence.add(im);
    };
    inst(new THREE.BoxGeometry(0.7, H + 0.6, 0.7), postMat, postPts, (H + 0.6) / 2);
    inst(new THREE.BoxGeometry(0.16, H - 0.4, 0.16), barMat, barPts, (H - 0.4) / 2 + 0.2);

    // Gerbang utama: pilar bata + papan nama melengkung + 2 daun gerbang TERBUKA (berayun ke dalam).
    const gz = GATE.at, half = GATE.w / 2, gx = X1;
    const pillarMat = new THREE.MeshLambertMaterial({ color: 0x9a3412 });
    [gz - half - 1.2, gz + half + 1.2].forEach(pz => {
      const pil = new THREE.Mesh(new THREE.BoxGeometry(2.4, 9, 2.4), pillarMat);
      pil.position.set(gx, 4.5, pz); pil.castShadow = true;
      const cap = new THREE.Mesh(new THREE.BoxGeometry(3.0, 0.6, 3.0), postMat);
      cap.position.set(gx, 9.3, pz);
      fence.add(pil, cap);
      this.colliders.push({ minX: gx - 1.2, maxX: gx + 1.2, minZ: pz - 1.2, maxZ: pz + 1.2, minY: 0, maxY: 9, height: 9 });
    });
    const arch = this.createSignCanvas('🏫 SMK SILBER — SELAMAT DATANG', GATE.w + 4.8, 3.2);
    arch.position.set(gx, 10.6, gz);
    arch.rotation.y = Math.PI / 2;
    fence.add(arch);
    const leafW = half - 0.4;
    [-1, 1].forEach(sgn => {
      const hinge = new THREE.Group();                         // engsel di sisi pilar
      hinge.position.set(gx, 0, gz + sgn * (half - 0.2));
      const leaf = new THREE.Group();
      const frame = new THREE.Mesh(new THREE.BoxGeometry(0.3, 0.35, leafW), railMat);
      frame.position.set(0, 4.4, -sgn * leafW / 2);
      const bottom = frame.clone(); bottom.position.y = 0.8;
      leaf.add(frame, bottom);
      for (let t = 0.6; t < leafW - 0.3; t += 1.0) {
        const bar = new THREE.Mesh(new THREE.BoxGeometry(0.14, 3.8, 0.14), barMat);
        bar.position.set(0, 2.6, -sgn * t);
        leaf.add(bar);
      }
      hinge.add(leaf);
      hinge.rotation.y = sgn * 1.25;                           // ±72° → terbuka ke DALAM halaman sekolah (−x)
      fence.add(hinge);
    });
    this.scene.add(fence);
  }

  /* ================= Renderer TOON / cel-shading =================
   * Semua material Lambert/Phong/Standard → MeshToonMaterial dengan gradasi 3 tingkat
   * (dipakai bersama; material asal yang sama → satu material toon). Garis tepi:
   * avatar = inverted hull (BackSide hitam), dinding/atap gedung = EdgesGeometry.
   * Garis tepi hanya pada kualitas sedang/tinggi agar HP lemah tetap ringan. */
  toonGradient() {
    if (!this.__toonGrad) {
      const data = new Uint8Array([105, 105, 105, 170, 170, 170, 225, 225, 225]); // gelap · tengah · terang (tidak terbakar)
      const tex = new THREE.DataTexture(data, 3, 1, THREE.RGBFormat);
      tex.minFilter = tex.magFilter = THREE.NearestFilter;
      tex.generateMipmaps = false;
      tex.needsUpdate = true;
      this.__toonGrad = tex;
    }
    return this.__toonGrad;
  }

  toonify(root) {
    if (!root) return;
    if (this.toonEnabled === undefined) this.toonEnabled = this.effectiveQuality !== 'low';
    if (!this.toonEnabled) return;   // perangkat lemah: tetap material ringan (Lambert, per-vertex)
    this.__toonCache = this.__toonCache || new Map();
    const conv = (m) => {
      if (!m || m.isMeshToonMaterial || m.isMeshBasicMaterial || m.isShaderMaterial || m.isLineBasicMaterial || m.isPointsMaterial) return m;
      if (!(m.isMeshLambertMaterial || m.isMeshPhongMaterial || m.isMeshStandardMaterial)) return m;
      let t = this.__toonCache.get(m);
      if (!t) {
        t = new THREE.MeshToonMaterial({
          color: m.color ? m.color.clone() : 0xffffff, map: m.map || null,
          emissive: m.emissive ? m.emissive.clone() : 0x000000, emissiveMap: m.emissiveMap || null,
          transparent: !!m.transparent, opacity: (m.opacity !== undefined) ? m.opacity : 1, side: m.side,
          gradientMap: this.toonGradient()
        });
        this.__toonCache.set(m, t);
      }
      return t;
    };
    root.traverse(o => {
      if (!o.isMesh) return;
      o.material = Array.isArray(o.material) ? o.material.map(conv) : conv(o.material);
    });
  }

  outlinesEnabled() {
    return this.toonEnabled !== false && this.effectiveQuality !== 'low';
  }

  /** Inverted hull hitam untuk avatar (kotak-kotak chibi → garis tepi kartun yang tegas). */
  addAvatarOutline(group, thickness = 1.07) {
    if (!group || !this.outlinesEnabled()) return;
    this.__hullMat = this.__hullMat || new THREE.MeshBasicMaterial({ color: 0x0b1020, side: THREE.BackSide });
    const meshes = [];
    group.traverse(o => { if (o.isMesh && !o.userData.isOutline && o.geometry && /^(Box|Sphere|Cylinder|Cone)Geometry$/.test(o.geometry.type) && o.geometry.parameters && !(o.geometry.parameters.openEnded)) meshes.push(o); });
    meshes.forEach(o => {
      // Hanya bagian besar (kepala/badan/lengan/kaki) → detail kecil tidak menggandakan draw call di HP lemah.
      if (!o.geometry.boundingSphere) o.geometry.computeBoundingSphere();
      if (o.geometry.boundingSphere && o.geometry.boundingSphere.radius < 0.6) return;
      const hull = new THREE.Mesh(o.geometry, this.__hullMat);
      hull.scale.setScalar(thickness);
      hull.userData.isOutline = true;
      o.add(hull);
    });
  }

  /** Garis tepi gedung: EdgesGeometry hanya untuk mesh bertanda userData.outline (dinding, atap). */
  addEdgeOutlines(root) {
    if (!root || !this.outlinesEnabled()) return;
    this.__edgeMat = this.__edgeMat || new THREE.LineBasicMaterial({ color: 0x0b1020 });
    const targets = [];
    root.traverse(o => { if (o.isMesh && o.userData.outline) targets.push(o); });
    targets.forEach(o => {
      const e = new THREE.LineSegments(new THREE.EdgesGeometry(o.geometry, 30), this.__edgeMat);
      e.userData.isOutline = true;
      o.add(e);
    });
  }

  /** Jalankan langkah pembangunan opsional; error dicatat tapi 3D tetap jalan (bukan menendang pemain). */
  safeStep(name, fn) {
    try { fn(); }
    catch (e) {
      console.warn('[3D] langkah "' + name + '" dilewati:', e);
      try { fetch('/api/client-error', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': ((document.querySelector('meta[name="csrf"]') || {}).content || '') }, body: JSON.stringify({ message: '3D step ' + name + ': ' + (e && e.message), url: location.pathname }) }); } catch (e2) {}
    }
  }

  addSurface(minX, maxX, minZ, maxZ, y) {
    this.surfaces.push({ minX, maxX, minZ, maxZ, y });
  }

  /** Ketinggian pijakan tertinggi di (x,z) yang masih bisa dinaiki dari ketinggian curY. */
  groundHeightAt(x, z, curY) {
    let best = 0;
    const reach = curY + STEP_UP;
    for (const s of this.surfaces) {
      if (x >= s.minX && x <= s.maxX && z >= s.minZ && z <= s.maxZ && s.y <= reach && s.y > best) best = s.y;
    }
    for (const r of this.ramps) {
      if (x < r.minX || x > r.maxX || z < r.minZ || z > r.maxZ) continue;
      const p = r.axis === 'x' ? x : z;
      const t = Math.max(0, Math.min(1, (p - r.a) / (r.b - r.a)));
      const hy = r.ya + (r.yb - r.ya) * t;
      if (hy <= reach && hy > best) best = hy;
    }
    return best;
  }

  /** True bila titik berada di lantai gedung yang sedang disembunyikan (di atas pemain). */
  isHiddenByLevel(pos) {
    const h = this.hiddenAbove;
    if (!h) return false;
    const bb = h.b.bounds;
    return pos.y > h.y && Math.abs(pos.x - bb.x) <= bb.width / 2 && Math.abs(pos.z - bb.z) <= bb.depth / 2;
  }

  /** Nomor lantai (1..n) dari ketinggian y. */
  levelAt(y) {
    return Math.max(1, Math.floor((y + 1) / FLOOR_H) + 1);
  }

  /** Procedurally build richly detailed interior furniture for each building */
  buildBuildingInterior(group, b, w, h, d) {
    const id = b.id || '';
    const type = b.type || '';

    // ==========================================
    // 1. GATE MAIN / POS SATPAM
    // ==========================================
    if (id === 'gate-main' || type === 'gate') {
      // Guard Checkpoint Desk
      this.addDeskWithChair(group, 0, 0.2, 0, 0x1e293b, 0x0284c7);
      // CCTV monitor bank on desk
      const cctvGeo = new THREE.BoxGeometry(2.4, 1.4, 0.2);
      const cctvMat = new THREE.MeshLambertMaterial({ color: 0x38bdf8, emissive: 0x0284c7 });
      const cctv = new THREE.Mesh(cctvGeo, cctvMat);
      cctv.position.set(0, 2.7, 0);
      group.add(cctv);
      // First aid kit on wall
      const fAid = new THREE.Mesh(new THREE.BoxGeometry(1.2, 1.2, 0.2), new THREE.MeshLambertMaterial({ color: 0xef4444 }));
      fAid.position.set(-w / 2 + 1.2, 4.0, 0);
      group.add(fAid);
      return;
    }

    // ==========================================
    // 2. PERPUSTAKAAN (LIBRARY)
    // ==========================================
    if (id === 'perpus' || type === 'library') {
      // Multiple double-sided bookshelves
      [-w / 4, 0, w / 4].forEach(bx => {
        this.addBookshelf(group, bx, 0.2, -d / 4, 8.0, 7.0, 1.8);
      });
      // Librarian counter near door
      this.addDeskWithChair(group, -w / 4, 0.2, d / 4, 0x78350f, 0x1e293b);
      this.addComputerWorkstation(group, -w / 4, 0.2, d / 4);
      // Reading study tables
      this.addStudyTable(group, w / 4, 0.2, d / 4);
      return;
    }

    // ==========================================
    // 3. BANK MINI SMK SILBER
    // ==========================================
    if (id === 'bank-mini' || type === 'bank') {
      // Teller Service Counter with Glass Panels
      const counter = new THREE.Mesh(
        new THREE.BoxGeometry(w - 6, 2.6, 2.4),
        new THREE.MeshLambertMaterial({ color: 0x1e3a8a })
      );
      counter.position.set(0, 1.4, -2);
      group.add(counter);
      // Glass divider panel
      const glass = new THREE.Mesh(
        new THREE.BoxGeometry(w - 8, 2.2, 0.2),
        new THREE.MeshLambertMaterial({ color: 0x38bdf8, transparent: true, opacity: 0.65 })
      );
      glass.position.set(0, 3.8, -2);
      group.add(glass);
      // 2 ATM machines on side wall
      this.addAtmMachine(group, -w / 2 + 2.5, 0.2, 2.5);
      this.addAtmMachine(group, -w / 2 + 5.5, 0.2, 2.5);
      // Customer waiting bench
      this.addWaitingBench(group, w / 4, 0.2, 3);
      return;
    }

    // ==========================================
    // 4. MINI MARKET SMK SILBER
    // ==========================================
    if (id === 'mini-market' || type === 'market') {
      // 2 Center grocery display gondolas
      this.addGroceryShelf(group, -w / 4, 0.2, -1, 7.0, 4.8, 2.2);
      this.addGroceryShelf(group, w / 4, 0.2, -1, 7.0, 4.8, 2.2);
      // Refrigerated Beverage Coolers with glowing cool-blue doors
      this.addDrinkCooler(group, -w / 2 + 2.4, 0.2, -d / 3);
      this.addDrinkCooler(group, -w / 2 + 6.0, 0.2, -d / 3);
      // Cashier checkout counter
      const cashier = new THREE.Mesh(
        new THREE.BoxGeometry(5.5, 2.4, 2.0),
        new THREE.MeshLambertMaterial({ color: 0x0284c7 })
      );
      cashier.position.set(w / 4, 1.3, d / 3);
      group.add(cashier);
      this.addComputerWorkstation(group, w / 4, 0.2, d / 3);
      return;
    }

    // ==========================================
    // 5. MUSHOLAH SEKOLAH
    // ==========================================
    if (id === 'musholah' || type === 'prayer') {
      // Recessed Mihrab Niche in Qibla (Back) Wall
      const mihrabGeo = new THREE.BoxGeometry(4.8, 6.5, 1.8);
      const mihrabMat = new THREE.MeshLambertMaterial({ color: 0x14532d });
      const mihrab = new THREE.Mesh(mihrabGeo, mihrabMat);
      mihrab.position.set(0, 3.3, -d / 2 + 0.9);
      group.add(mihrab);

      // Wooden Mimbar / Sermon Pulpit
      const mimbar = new THREE.Mesh(
        new THREE.BoxGeometry(2.2, 3.2, 2.2),
        new THREE.MeshLambertMaterial({ color: 0x78350f })
      );
      mimbar.position.set(3.2, 1.7, -d / 2 + 2.0);
      group.add(mimbar);

      // Wooden Rehal Stands for Al-Qur'an
      [-4, 4].forEach(rx => {
        const rehal = new THREE.Mesh(
          new THREE.BoxGeometry(1.4, 0.6, 1.0),
          new THREE.MeshLambertMaterial({ color: 0xb45309 })
        );
        rehal.position.set(rx, 0.4, -d / 4);
        group.add(rehal);
      });
      return;
    }

    // ==========================================
    // 6. RUANG PRAKTIK SISWA (RPS / WORKSHOP)
    // ==========================================
    if (id === 'ruang-praktik' || type === 'workshop') {
      // Heavy Industrial Workbenches
      this.addWorkbench(group, -w / 4, 0.2, -d / 4);
      this.addWorkbench(group, w / 4, 0.2, -d / 4);
      this.addWorkbench(group, 0, 0.2, d / 4);
      // Tool Pegboard on Back Wall
      const pegGeo = new THREE.BoxGeometry(12.0, 4.0, 0.2);
      const pegMat = new THREE.MeshLambertMaterial({ color: 0x475569 });
      const peg = new THREE.Mesh(pegGeo, pegMat);
      peg.position.set(0, 5.0, -d / 2 + 0.6);
      group.add(peg);
      // Electronic Test Osciloscope Bench
      this.addElectronicTestStation(group, -w / 2 + 3.0, 0.2, 0);
      return;
    }

    // ==========================================
    // 7. LAB JARINGAN KOMPUTER TJKT
    // ==========================================
    if (id === 'lab-jaringan' || (id.includes('lab') && id.includes('jaringan'))) {
      // 19" Server Racks with Blinking LEDs
      this.addServerRack(group, -w / 4 - 3, 0.2, -d / 4);
      this.addServerRack(group, -w / 4, 0.2, -d / 4);
      // Cisco Router & Switch Rack
      this.addNetworkSwitchRack(group, -w / 4 + 3, 0.2, -d / 4);
      // Student Dual-Monitor Workstations
      this.addComputerWorkstation(group, w / 4, 0.2, -d / 4);
      this.addComputerWorkstation(group, w / 4, 0.2, 0);
      this.addComputerWorkstation(group, w / 4, 0.2, d / 4);
      return;
    }

    // ==========================================
    // 8. GEDUNG D (AULA & KEGIATAN SISWA)
    // ==========================================
    if (id === 'gedung-d') {
      // Raised Presentation Stage
      const stage = new THREE.Mesh(
        new THREE.BoxGeometry(w - 6, 1.2, 8.0),
        new THREE.MeshLambertMaterial({ color: 0x475569 })
      );
      stage.position.set(0, 0.7, -d / 2 + 5.0);
      group.add(stage);

      // Giant Projector Presentation Screen on Wall
      const screen = new THREE.Mesh(
        new THREE.BoxGeometry(w - 10, 5.5, 0.2),
        new THREE.MeshLambertMaterial({ color: 0xf8fafc, emissive: 0x0284c7 })
      );
      screen.position.set(0, 5.8, -d / 2 + 0.7);
      group.add(screen);

      // Speaker Podium on Stage
      const podium = new THREE.Mesh(
        new THREE.BoxGeometry(1.8, 2.8, 1.4),
        new THREE.MeshLambertMaterial({ color: 0x78350f })
      );
      podium.position.set(0, 2.2, -d / 2 + 3.5);
      group.add(podium);

      // Audience Chair Rows
      [-3, 2, 7].forEach(cz => {
        [-w / 4, 0, w / 4].forEach(cx => {
          this.addAudienceChair(group, cx, 0.2, cz);
        });
      });
      return;
    }

    // ==========================================
    // 9. GEDUNG A (ADMINISTRASI & KEJURUAN)
    // ==========================================
    if (id === 'gedung-a') {
      // Curved Reception Desk in Lobby
      const recDesk = new THREE.Mesh(
        new THREE.BoxGeometry(10.0, 2.6, 2.4),
        new THREE.MeshLambertMaterial({ color: 0x1e3a8a })
      );
      recDesk.position.set(0, 1.4, 0);
      group.add(recDesk);
      this.addComputerWorkstation(group, 0, 0.2, 0);

      // Lobby Waiting Area: Leather Sofas & Glass Table
      this.addLoungeSofa(group, -w / 4, 0.2, 3, 0x1e293b);
      this.addLoungeSofa(group, w / 4, 0.2, 3, 0x1e293b);

      // Trophy Display Cabinet with Golden Trophies
      this.addTrophyCabinet(group, -w / 3, 0.2, -d / 4);

      // Indoor Potted Plants
      this.addPottedPlant(group, -w / 2 + 3, 0.2, d / 3);
      this.addPottedPlant(group, w / 2 - 3, 0.2, d / 3);
      return;
    }

    // ==========================================
    // 10. GEDUNG B & C (RUANG KELAS & LAB KOMPUTER)
    // ==========================================
    // Classroom Desk Rows (Double Student Desks)
    const rowZ = [-d / 4, 0, d / 4];
    const colX = [-w / 4, 0, w / 4];
    rowZ.forEach(rz => {
      colX.forEach(cx => {
        this.addDeskWithChair(group, cx, 0.2, rz, 0x78350f, 0x0284c7);
      });
    });

    // Front Blackboard / Whiteboard
    const board = new THREE.Mesh(
      new THREE.BoxGeometry(Math.min(14.0, w * 0.5), 4.2, 0.2),
      new THREE.MeshLambertMaterial({ color: 0x064e3b }) // Classic School Greenboard
    );
    board.position.set(0, 5.0, -d / 2 + 0.6);
    group.add(board);

    // Teacher's Podium Desk with Laptop
    this.addDeskWithChair(group, w / 4, 0.2, -d / 3, 0x1e293b, 0x0f172a);
    this.addComputerWorkstation(group, w / 4, 0.2, -d / 3);
  }

  // --- Reusable 3D Interior Prop Helpers ---

  addDeskWithChair(group, x, y, z, deskColor = 0x78350f, chairColor = 0x0284c7) {
    // Desk Top
    const desk = new THREE.Mesh(
      new THREE.BoxGeometry(3.6, 0.2, 2.0),
      new THREE.MeshLambertMaterial({ color: deskColor })
    );
    desk.position.set(x, y + 1.8, z);
    // Desk Legs
    const legGeo = new THREE.CylinderGeometry(0.08, 0.08, 1.8, 6);
    const legMat = new THREE.MeshLambertMaterial({ color: 0x334155 });
    [-1.6, 1.6].forEach(lx => {
      [-0.8, 0.8].forEach(lz => {
        const leg = new THREE.Mesh(legGeo, legMat);
        leg.position.set(x + lx, y + 0.9, z + lz);
        group.add(leg);
      });
    });
    group.add(desk);

    // Chair behind desk
    const chairSeat = new THREE.Mesh(
      new THREE.BoxGeometry(1.6, 0.15, 1.6),
      new THREE.MeshLambertMaterial({ color: chairColor })
    );
    chairSeat.position.set(x, y + 1.1, z - 1.5);
    const chairBack = new THREE.Mesh(
      new THREE.BoxGeometry(1.6, 1.4, 0.15),
      new THREE.MeshLambertMaterial({ color: chairColor })
    );
    chairBack.position.set(x, y + 1.9, z - 2.2);
    group.add(chairSeat, chairBack);
    this.seats.push({ obj: chairSeat, top: 0.075 });
  }

  addComputerWorkstation(group, x, y, z) {
    // Monitor Screen
    const mon = new THREE.Mesh(
      new THREE.BoxGeometry(1.8, 1.2, 0.15),
      new THREE.MeshLambertMaterial({ color: 0x38bdf8, emissive: 0x0284c7 })
    );
    mon.position.set(x, y + 2.6, z);
    // Stand
    const stand = new THREE.Mesh(
      new THREE.CylinderGeometry(0.1, 0.2, 0.6, 6),
      new THREE.MeshLambertMaterial({ color: 0x0f172a })
    );
    stand.position.set(x, y + 2.1, z);
    // Keyboard
    const kb = new THREE.Mesh(
      new THREE.BoxGeometry(1.4, 0.06, 0.6),
      new THREE.MeshLambertMaterial({ color: 0x0f172a })
    );
    kb.position.set(x, y + 1.94, z + 0.4);
    group.add(mon, stand, kb);
  }

  addBookshelf(group, x, y, z, w = 6.0, h = 6.5, d = 1.6) {
    const frame = new THREE.Mesh(
      new THREE.BoxGeometry(w, h, d),
      new THREE.MeshLambertMaterial({ color: 0x78350f })
    );
    frame.position.set(x, y + h / 2, z);
    group.add(frame);

    // Rows of colorful book blocks
    const bookColors = [0xef4444, 0x0284c7, 0xf59e0b, 0x10b981, 0x8b5cf6];
    for (let shelf = 0; shelf < 3; shelf++) {
      const sy = y + 1.4 + shelf * 2.0;
      for (let bk = 0; bk < 4; bk++) {
        const bx = x - w / 2 + 1.0 + bk * (w / 4);
        const book = new THREE.Mesh(
          new THREE.BoxGeometry(w / 5, 1.3, d + 0.1),
          new THREE.MeshLambertMaterial({ color: bookColors[(shelf + bk) % bookColors.length] })
        );
        book.position.set(bx, sy, z);
        group.add(book);
      }
    }
  }

  addStudyTable(group, x, y, z) {
    const table = new THREE.Mesh(
      new THREE.BoxGeometry(6.0, 0.2, 3.5),
      new THREE.MeshLambertMaterial({ color: 0xd97706 })
    );
    table.position.set(x, y + 1.8, z);
    group.add(table);
    // Green Banker Lamp
    const lamp = new THREE.Mesh(
      new THREE.CylinderGeometry(0.4, 0.4, 0.4, 8),
      new THREE.MeshLambertMaterial({ color: 0x15803d, emissive: 0x86efac })
    );
    lamp.position.set(x, y + 2.2, z);
    group.add(lamp);
  }

  addAtmMachine(group, x, y, z) {
    const body = new THREE.Mesh(
      new THREE.BoxGeometry(2.2, 5.2, 2.0),
      new THREE.MeshLambertMaterial({ color: 0x1e3a8a })
    );
    body.position.set(x, y + 2.6, z);
    const screen = new THREE.Mesh(
      new THREE.BoxGeometry(1.4, 1.0, 0.1),
      new THREE.MeshLambertMaterial({ color: 0x10b981, emissive: 0x10b981 })
    );
    screen.position.set(x, y + 3.4, z + 1.02);
    group.add(body, screen);
  }

  addWaitingBench(group, x, y, z) {
    const bench = new THREE.Mesh(
      new THREE.BoxGeometry(6.0, 0.6, 2.0),
      new THREE.MeshLambertMaterial({ color: 0x475569 })
    );
    bench.position.set(x, y + 0.9, z);
    group.add(bench);
    this.seats.push({ obj: bench, top: 0.3 });
  }

  addGroceryShelf(group, x, y, z, w = 7.0, h = 4.8, d = 2.0) {
    const shelf = new THREE.Mesh(
      new THREE.BoxGeometry(w, h, d),
      new THREE.MeshLambertMaterial({ color: 0x334155 })
    );
    shelf.position.set(x, y + h / 2, z);
    group.add(shelf);
    // Colorful snack and drink goods packages on shelves
    const prodColors = [0xef4444, 0xf59e0b, 0x10b981, 0x38bdf8];
    for (let i = 0; i < 6; i++) {
      const prod = new THREE.Mesh(
        new THREE.BoxGeometry(0.8, 0.8, d + 0.1),
        new THREE.MeshLambertMaterial({ color: prodColors[i % prodColors.length] })
      );
      prod.position.set(x - w / 2 + 1.0 + i * 1.0, y + 2.0, z);
      group.add(prod);
    }
  }

  addDrinkCooler(group, x, y, z) {
    const body = new THREE.Mesh(
      new THREE.BoxGeometry(3.2, 6.5, 2.2),
      new THREE.MeshLambertMaterial({ color: 0x0f172a })
    );
    body.position.set(x, y + 3.25, z);
    const glass = new THREE.Mesh(
      new THREE.BoxGeometry(2.8, 5.8, 0.1),
      new THREE.MeshLambertMaterial({ color: 0x38bdf8, emissive: 0x0284c7, transparent: true, opacity: 0.8 })
    );
    glass.position.set(x, y + 3.25, z + 1.12);
    group.add(body, glass);
  }

  addWorkbench(group, x, y, z) {
    const top = new THREE.Mesh(
      new THREE.BoxGeometry(6.5, 0.4, 3.0),
      new THREE.MeshLambertMaterial({ color: 0x78350f })
    );
    top.position.set(x, y + 2.0, z);
    const frame = new THREE.Mesh(
      new THREE.BoxGeometry(6.2, 1.8, 2.6),
      new THREE.MeshLambertMaterial({ color: 0x334155 })
    );
    frame.position.set(x, y + 0.9, z);
    // Bench Vise tool mounted on corner
    const vise = new THREE.Mesh(
      new THREE.BoxGeometry(0.8, 0.8, 0.8),
      new THREE.MeshLambertMaterial({ color: 0x0284c7 })
    );
    vise.position.set(x + 2.8, y + 2.4, z + 1.0);
    group.add(top, frame, vise);
  }

  addElectronicTestStation(group, x, y, z) {
    const osc = new THREE.Mesh(
      new THREE.BoxGeometry(2.2, 1.4, 1.6),
      new THREE.MeshLambertMaterial({ color: 0x1e293b })
    );
    osc.position.set(x, y + 2.2, z);
    const screen = new THREE.Mesh(
      new THREE.BoxGeometry(1.2, 0.9, 0.1),
      new THREE.MeshLambertMaterial({ color: 0x22c55e, emissive: 0x22c55e })
    );
    screen.position.set(x, y + 2.2, z + 0.82);
    group.add(osc, screen);
  }

  addServerRack(group, x, y, z) {
    const rack = new THREE.Mesh(
      new THREE.BoxGeometry(2.6, 7.5, 2.6),
      new THREE.MeshLambertMaterial({ color: 0x0f172a })
    );
    rack.position.set(x, y + 3.75, z);
    // Blinking Server Status LEDs
    const ledMat = new THREE.MeshLambertMaterial({ color: 0x22c55e, emissive: 0x22c55e });
    for (let row = 0; row < 5; row++) {
      const led = new THREE.Mesh(new THREE.SphereGeometry(0.08, 6, 6), ledMat);
      led.position.set(x + 0.8, y + 2.0 + row * 1.1, z + 1.32);
      group.add(led);
    }
    group.add(rack);
  }

  addNetworkSwitchRack(group, x, y, z) {
    const swRack = new THREE.Mesh(
      new THREE.BoxGeometry(2.6, 6.0, 2.2),
      new THREE.MeshLambertMaterial({ color: 0x1e293b })
    );
    swRack.position.set(x, y + 3.0, z);
    group.add(swRack);
  }

  addAudienceChair(group, x, y, z) {
    const seat = new THREE.Mesh(
      new THREE.BoxGeometry(1.8, 0.2, 1.6),
      new THREE.MeshLambertMaterial({ color: 0x1e3a8a })
    );
    seat.position.set(x, y + 1.0, z);
    const back = new THREE.Mesh(
      new THREE.BoxGeometry(1.8, 1.6, 0.2),
      new THREE.MeshLambertMaterial({ color: 0x1e3a8a })
    );
    back.position.set(x, y + 1.8, z - 0.7);
    group.add(seat, back);
    this.seats.push({ obj: seat, top: 0.1 });
  }

  addLoungeSofa(group, x, y, z, color = 0x1e293b) {
    const seat = new THREE.Mesh(
      new THREE.BoxGeometry(5.8, 0.8, 2.6),
      new THREE.MeshLambertMaterial({ color })
    );
    seat.position.set(x, y + 1.0, z);
    const back = new THREE.Mesh(
      new THREE.BoxGeometry(5.8, 1.8, 0.6),
      new THREE.MeshLambertMaterial({ color })
    );
    back.position.set(x, y + 2.0, z - 1.0);
    group.add(seat, back);
    this.seats.push({ obj: seat, top: 0.4 });
  }

  addTrophyCabinet(group, x, y, z) {
    const cab = new THREE.Mesh(
      new THREE.BoxGeometry(5.0, 6.0, 1.8),
      new THREE.MeshLambertMaterial({ color: 0x38bdf8, transparent: true, opacity: 0.55 })
    );
    cab.position.set(x, y + 3.0, z);
    group.add(cab);
    // Gold Trophies inside
    [-1.4, 0, 1.4].forEach(tx => {
      const trop = new THREE.Mesh(
        new THREE.CylinderGeometry(0.3, 0.1, 1.2, 8),
        new THREE.MeshLambertMaterial({ color: 0xf59e0b, emissive: 0xd97706 })
      );
      trop.position.set(x + tx, y + 2.4, z);
      group.add(trop);
    });
  }

  addPottedPlant(group, x, y, z) {
    const pot = new THREE.Mesh(
      new THREE.CylinderGeometry(0.8, 0.6, 1.4, 8),
      new THREE.MeshLambertMaterial({ color: 0xb45309 })
    );
    pot.position.set(x, y + 0.7, z);
    const bush = new THREE.Mesh(
      new THREE.SphereGeometry(1.2, 8, 8),
      new THREE.MeshLambertMaterial({ color: 0x15803d })
    );
    bush.position.set(x, y + 2.2, z);
    group.add(pot, bush);
  }

  /** Add realistic modern classroom windows across building facade */
  /** Satu baris jendela depan & belakang untuk satu lantai (y0 = dasar lantai). Material dibagi. */
  addBuildingWindows(group, w, h, d, hasFloor2, y0 = 0) {
    const winWidth = 3.4, winHeight = 2.4, gap = 5.5;
    const count = Math.max(2, Math.floor((w - 10) / gap));
    const startX = -((count - 1) * gap) / 2;
    if (!this.__winMats) {
      this.__winMats = {
        frame: new THREE.MeshLambertMaterial({ color: 0xffffff }),
        glass: new THREE.MeshLambertMaterial({ color: 0x38bdf8, transparent: true, opacity: 0.88 })
      };
    }
    const floorY = y0 + 4.6;
    const frameGeo = new THREE.BoxGeometry(winWidth + 0.3, winHeight + 0.3, 0.2);
    const glassGeo = new THREE.BoxGeometry(winWidth, winHeight, 0.22);
    for (let i = 0; i < count; i++) {
      const wx = startX + i * gap;
      if (y0 === 0 && Math.abs(wx) < 5.0) continue; // pintu masuk di lantai dasar
      [d / 2 + 0.12, -d / 2 - 0.12].forEach((fz, side) => {
        const frame = new THREE.Mesh(frameGeo, this.__winMats.frame);
        frame.position.set(wx, floorY, fz);
        const glass = new THREE.Mesh(glassGeo, this.__winMats.glass);
        glass.position.set(wx, floorY, fz + (side ? -0.03 : 0.03));
        group.add(frame, glass);
        this.windowMeshes.push(glass);
      });
    }
  }

  createSignCanvas(text, w = 18, h = 4) {
    const canvas = document.createElement('canvas');
    canvas.width = 512;
    canvas.height = 100;
    const ctx = canvas.getContext('2d');

    // Modern glassmorphic banner gradient
    const grad = ctx.createLinearGradient(0, 0, 512, 0);
    grad.addColorStop(0, '#0f172a');
    grad.addColorStop(0.5, '#1e293b');
    grad.addColorStop(1, '#0f172a');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, 512, 100);

    // Glowing border
    ctx.strokeStyle = '#22d3ee';
    ctx.lineWidth = 5;
    ctx.strokeRect(3, 3, 506, 94);

    // Text with soft shadow
    ctx.shadowColor = '#22d3ee';
    ctx.shadowBlur = 10;
    ctx.fillStyle = '#f8fafc';
    ctx.font = 'bold 32px sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(text, 256, 50);

    const tex = new THREE.CanvasTexture(canvas);
    tex.encoding = THREE.sRGBEncoding;
    tex.anisotropy = 4;
    const geo = new THREE.PlaneGeometry(w, h);
    const mat = new THREE.MeshBasicMaterial({ map: tex, side: THREE.FrontSide });
    // Dua sisi saling membelakangi: DoubleSide satu plane membuat teks terbaca MIRROR dari belakang.
    const sign = new THREE.Group();
    const front = new THREE.Mesh(geo, mat);
    const back = new THREE.Mesh(geo, mat);
    back.rotation.y = Math.PI;
    sign.add(front, back);
    return sign;
  }

  createStairsMesh(s) {
    const stairs = new THREE.Group();
    stairs.position.set(s.x, s.y || 0, s.z);
    const steps = 14;
    const stepDepth = 1.6;
    const stepHeight = s.maxHeight / steps;

    for (let i = 0; i < steps; i++) {
      const step = new THREE.Mesh(
        new THREE.BoxGeometry(8, stepHeight, stepDepth),
        new THREE.MeshLambertMaterial({ color: 0x475569 })
      );
      step.position.set(0, (i + 0.5) * stepHeight, i * stepDepth);
      step.castShadow = true;
      step.receiveShadow = true;
      stairs.add(step);
    }

    const rail = new THREE.Mesh(
      new THREE.BoxGeometry(0.5, 3, steps * stepDepth),
      new THREE.MeshLambertMaterial({ color: 0xf59e0b })
    );
    rail.position.set(4, s.maxHeight / 2 + 1.5, (steps * stepDepth) / 2);
    stairs.add(rail);

    this.scene.add(stairs);
  }

  buildFutsalField() {
    const futsal = new THREE.Group();
    const fz = (this.campusData && this.campusData.sports && this.campusData.sports.futsal) || { x: 88, z: 0, width: 60, depth: 36 };
    futsal.position.set(fz.x, 0.05, fz.z);

    // Red Athletic Tartan Running Track Border
    const track = new THREE.Mesh(
      new THREE.PlaneGeometry(68, 44),
      new THREE.MeshLambertMaterial({ color: 0xc2410c })
    );
    track.rotation.x = -Math.PI / 2;
    track.receiveShadow = true;
    futsal.add(track);

    // Green Futsal Court Turf
    const turf = new THREE.Mesh(
      new THREE.PlaneGeometry(60, 36),
      new THREE.MeshLambertMaterial({ color: 0x15803d })
    );
    turf.rotation.x = -Math.PI / 2;
    turf.position.y = 0.02;
    turf.receiveShadow = true;
    futsal.add(turf);

    // Court Lines
    const lines = new THREE.LineSegments(
      new THREE.EdgesGeometry(new THREE.PlaneGeometry(58, 34)),
      new THREE.MeshBasicMaterial({ color: 0xffffff })
    );
    lines.rotation.x = -Math.PI / 2;
    lines.position.y = 0.04;
    futsal.add(lines);

    // Center Circle
    const centerCircle = new THREE.LineLoop(
      new THREE.BufferGeometry().setFromPoints(
        new THREE.Path().absarc(0, 0, 6, 0, Math.PI * 2, true).getPoints(32).map(p => new THREE.Vector3(p.x, 0.04, p.y))
      ),
      new THREE.MeshBasicMaterial({ color: 0xffffff })
    );
    futsal.add(centerCircle);

    // Goals with net wireframe
    const goalGeo = new THREE.BoxGeometry(2, 6, 12);
    const goalMat = new THREE.MeshBasicMaterial({ color: 0xffffff, wireframe: true });
    const g1 = new THREE.Mesh(goalGeo, goalMat); g1.position.set(-29, 3, 0);
    const g2 = new THREE.Mesh(goalGeo, goalMat); g2.position.set(29, 3, 0);
    futsal.add(g1, g2);

    this.scene.add(futsal);

    // Interactive Soccer Ball
    // Bola bermotif (panel gelap) agar putarannya terlihat saat menggelinding. Radius 0.9 ≈ proporsional karakter.
    const bc = document.createElement('canvas'); bc.width = 128; bc.height = 64;
    const bg = bc.getContext('2d'); bg.fillStyle = '#ffffff'; bg.fillRect(0, 0, 128, 64); bg.fillStyle = '#111827';
    [[16, 16], [56, 10], [96, 18], [36, 44], [76, 48], [116, 44]].forEach(([x, y]) => { bg.beginPath(); bg.arc(x, y, 8, 0, Math.PI * 2); bg.fill(); });
    const ballMat = new THREE.MeshLambertMaterial({ map: new THREE.CanvasTexture(bc) });
    this.ballRadius = 0.9;
    this.soccerBall = new THREE.Mesh(new THREE.SphereGeometry(this.ballRadius, 16, 12), ballMat);
    this.soccerBall.position.set(fz.x, this.ballRadius, fz.z);
    // Batas lapangan futsal (campus.json sports.futsal) — bola memantul di pinggir.
    this.ballBounds = { minX: fz.x - fz.width / 2, maxX: fz.x + fz.width / 2, minZ: fz.z - fz.depth / 2, maxZ: fz.z + fz.depth / 2 };
    this.soccerBall.castShadow = true;
    this.scene.add(this.soccerBall);
  }

  buildGarden() {
    // 1. Central Courtyard Fountain
    const fountainGroup = new THREE.Group();
    fountainGroup.position.set(-18, 0, 16);

    const basin = new THREE.Mesh(
      new THREE.CylinderGeometry(8.5, 9.5, 2.2, 24),
      new THREE.MeshLambertMaterial({ color: 0x475569 })
    );
    basin.position.y = 1.1;
    basin.castShadow = true;
    basin.receiveShadow = true;
    fountainGroup.add(basin);

    // Water Surface
    const waterGeo = new THREE.CylinderGeometry(8.0, 8.0, 0.4, 24);
    const waterMat = new THREE.MeshLambertMaterial({
      color: 0x0284c7,
      transparent: true,
      opacity: 0.85
    });
    this.fountainWater = new THREE.Mesh(waterGeo, waterMat);
    this.fountainWater.position.y = 2.0;
    fountainGroup.add(this.fountainWater);

    // Fountain Spout / Pedestal
    const spout = new THREE.Mesh(
      new THREE.CylinderGeometry(1.5, 2.0, 4.5, 16),
      new THREE.MeshLambertMaterial({ color: 0x94a3b8 })
    );
    spout.position.y = 3.2;
    fountainGroup.add(spout);

    this.scene.add(fountainGroup);

    // 2. National Flagpole in front of Main Building
    const flagGroup = new THREE.Group();
    flagGroup.position.set(0, 0, 60);

    const pole = new THREE.Mesh(
      new THREE.CylinderGeometry(0.25, 0.35, 24, 12),
      new THREE.MeshLambertMaterial({ color: 0xe2e8f0 })
    );
    pole.position.y = 12;
    pole.castShadow = true;
    flagGroup.add(pole);

    // Indonesian Flag (Red & White)
    const redFlag = new THREE.Mesh(
      new THREE.PlaneGeometry(6, 2),
      new THREE.MeshLambertMaterial({ color: 0xef4444, side: THREE.DoubleSide })
    );
    redFlag.position.set(3, 22, 0);
    const whiteFlag = new THREE.Mesh(
      new THREE.PlaneGeometry(6, 2),
      new THREE.MeshLambertMaterial({ color: 0xffffff, side: THREE.DoubleSide })
    );
    whiteFlag.position.set(3, 20, 0);
    flagGroup.add(redFlag, whiteFlag);

    this.scene.add(flagGroup);

    // 3. Stylized Campus Benches
    const benchCoords = [
      [-18, 30], [-18, 2], [8, 16], [-44, 16]
    ];
    benchCoords.forEach(([bx, bz]) => {
      const bench = new THREE.Group();
      bench.position.set(bx, 0, bz);
      const seat = new THREE.Mesh(new THREE.BoxGeometry(6, 0.4, 2), new THREE.MeshLambertMaterial({ color: 0x78350f }));
      seat.position.y = 1.6;
      seat.castShadow = true;
      const leg1 = new THREE.Mesh(new THREE.BoxGeometry(0.4, 1.6, 1.8), new THREE.MeshLambertMaterial({ color: 0x334155 }));
      leg1.position.set(-2.5, 0.8, 0);
      const leg2 = leg1.clone();
      leg2.position.x = 2.5;
      bench.add(seat, leg1, leg2);
      this.scene.add(bench);
    });

    // 4. Pohon kampus — InstancedMesh satu geometri per jenis (hemat ±50 draw call).
    const treeCoords = [
      [-18, -12, 'oak'], [-35, 16, 'pine'], [5, 25, 'sakura'], [-50, 45, 'oak'],
      [30, 45, 'pine'], [80, 45, 'oak'], [-120, 20, 'pine'], [-80, 16, 'sakura'],
      [130, -25, 'oak'], [-60, -60, 'pine'], [60, -60, 'sakura'],
      // Expanded campus perimeter trees
      [-210, 20, 'pine'], [-210, -50, 'oak'], [-180, -110, 'pine'], [160, -110, 'oak'],
      [190, 20, 'sakura'], [-120, -120, 'oak'], [90, -120, 'sakura'], [0, -160, 'pine'],
      [0, 155, 'sakura'], [-150, 80, 'oak'], [150, 80, 'pine'], [-210, 80, 'sakura']
    ];

    const trunkGeo = new THREE.CylinderGeometry(0.8, 1.2, 7, 8);
    const pine1Geo = new THREE.ConeGeometry(5.5, 8, 8);
    const pine2Geo = new THREE.ConeGeometry(4.2, 7, 8);
    const oakGeo = new THREE.DodecahedronGeometry(5.8, 1);
    const sakuraGeo = new THREE.DodecahedronGeometry(5.2, 1);
    const trunkMat = new THREE.MeshLambertMaterial({ color: 0x78350f });
    const pine1Mat = new THREE.MeshLambertMaterial({ color: 0x15803d });
    const pine2Mat = new THREE.MeshLambertMaterial({ color: 0x16a34a });
    const oakMat = new THREE.MeshLambertMaterial({ color: 0x16a34a });
    const sakuraMat = new THREE.MeshLambertMaterial({ color: 0xf472b6 });

    const counts = { trunk: treeCoords.length, pine: 0, oak: 0, sakura: 0 };
    for (const t of treeCoords) counts[t[2]]++;
    const mkInst = (geo, mat, n) => {
      if (n === 0) return null;
      const im = new THREE.InstancedMesh(geo, mat, n);
      im.castShadow = true;
      im.instanceMatrix.setUsage(THREE.StaticDrawUsage);
      this.scene.add(im);
      return im;
    };
    const inst = {
      trunk: mkInst(trunkGeo, trunkMat, counts.trunk),
      pine1: mkInst(pine1Geo, pine1Mat, counts.pine),
      pine2: mkInst(pine2Geo, pine2Mat, counts.pine),
      oak: mkInst(oakGeo, oakMat, counts.oak),
      sakura: mkInst(sakuraGeo, sakuraMat, counts.sakura)
    };
    // Skala deterministik per pohon (variasi alami tanpa flicker antar-frame).
    const rndTree = (tx, tz) => {
      const v = Math.sin(tx * 12.9898 + tz * 78.233) * 43758.5453;
      return 0.92 + (v - Math.floor(v)) * 0.28;
    };
    const M = new THREE.Matrix4();
    const idx = { trunk: 0, pine1: 0, pine2: 0, oak: 0, sakura: 0 };
    for (const [tx, tz, type] of treeCoords) {
      const s = rndTree(tx, tz);
      const set = (mesh, y, key) => {
        if (!mesh) return;
        M.makeScale(s, s, s);
        M.setPosition(tx, y * s, tz);
        mesh.setMatrixAt(idx[key]++, M);
      };
      set(inst.trunk, 3.5, 'trunk');
      if (type === 'pine') { set(inst.pine1, 8, 'pine1'); set(inst.pine2, 12, 'pine2'); }
      else if (type === 'sakura') { set(inst.sakura, 9.5, 'sakura'); }
      else { set(inst.oak, 10, 'oak'); }
    }
    for (const k of ['trunk', 'pine1', 'pine2', 'oak', 'sakura']) {
      if (inst[k]) inst[k].instanceMatrix.needsUpdate = true;
    }
  }

  /** 7. Build Student Motorcycle Parking Zone (Parkiran Siswa SMK Silber) */
  buildParkingZone() {
    const parkGroup = new THREE.Group();
    const PK = (this.campusData && this.campusData.parking && this.campusData.parking.student) || { x: 145, z: -55 };
    parkGroup.position.set(PK.x, 0, PK.z);

    // Asphalt Pavement
    const asphaltGeo = new THREE.PlaneGeometry(62, 42);
    const asphaltMat = new THREE.MeshLambertMaterial({ color: 0x1e293b });
    const asphalt = new THREE.Mesh(asphaltGeo, asphaltMat);
    asphalt.rotation.x = -Math.PI / 2;
    asphalt.position.y = 0.03;
    asphalt.receiveShadow = true;
    parkGroup.add(asphalt);

    // Curb Border
    const curbGeo = new THREE.BoxGeometry(63, 0.4, 43);
    const curbMat = new THREE.MeshLambertMaterial({ color: 0x64748b });
    const curb = new THREE.Mesh(curbGeo, curbMat);
    curb.position.y = 0.2;
    parkGroup.add(curb);

    // Covered Steel Canopy Shelter
    const canopyRoof = new THREE.Mesh(
      new THREE.BoxGeometry(56, 0.6, 26),
      new THREE.MeshLambertMaterial({ color: 0x0284c7 })
    );
    canopyRoof.position.set(0, 7.5, 0);
    canopyRoof.castShadow = true;
    parkGroup.add(canopyRoof);

    // Canopy Support Steel Poles
    [-24, 0, 24].forEach(px => {
      [-10, 10].forEach(pz => {
        const pole = new THREE.Mesh(
          new THREE.CylinderGeometry(0.35, 0.35, 7.5, 8),
          new THREE.MeshLambertMaterial({ color: 0x94a3b8 })
        );
        pole.position.set(px, 3.75, pz);
        pole.castShadow = true;
        parkGroup.add(pole);
      });
    });

    // Parked Student Motorized Scooters (Stylized Low-poly Models)
    const bikeColors = [0xef4444, 0x3b82f6, 0x10b981, 0xf59e0b, 0x8b5cf6, 0xec4899];
    [-20, -12, -4, 4, 12, 20].forEach((bx, idx) => {
      const bike = new THREE.Group();
      bike.position.set(bx, 0, 0);
      const col = bikeColors[idx % bikeColors.length];

      // Chassis & Seat
      const body = new THREE.Mesh(new THREE.BoxGeometry(1.6, 1.4, 4.2), new THREE.MeshLambertMaterial({ color: col }));
      body.position.y = 1.3;
      body.castShadow = true;
      const seat = new THREE.Mesh(new THREE.BoxGeometry(1.4, 0.4, 2.6), new THREE.MeshLambertMaterial({ color: 0x0f172a }));
      seat.position.set(0, 2.1, -0.4);
      bike.add(body, seat);

      // Wheels
      const wMat = new THREE.MeshLambertMaterial({ color: 0x0f172a });
      const wGeo = new THREE.CylinderGeometry(0.8, 0.8, 0.5, 12);
      const frontW = new THREE.Mesh(wGeo, wMat);
      frontW.rotation.z = Math.PI / 2;
      frontW.position.set(0, 0.8, 1.8);
      const backW = frontW.clone();
      backW.position.z = -1.8;
      bike.add(frontW, backW);

      // Handlebars & Headlight
      const bar = new THREE.Mesh(new THREE.BoxGeometry(2.4, 0.25, 0.25), new THREE.MeshLambertMaterial({ color: 0x94a3b8 }));
      bar.position.set(0, 2.6, 1.6);
      const headlight = new THREE.Mesh(new THREE.SphereGeometry(0.35, 8, 8), new THREE.MeshBasicMaterial({ color: 0xfef08a }));
      headlight.position.set(0, 2.3, 2.2);
      bike.add(bar, headlight);

      parkGroup.add(bike);
    });

    // Signboard
    const sign = this.createSignCanvas("🅿️ PARKIRAN KENDARAAN SISWA", 28, 4.2);
    sign.position.set(0, 9.2, 13.2);
    parkGroup.add(sign);

    this.scene.add(parkGroup);

    // Colliders around parking canopy
    this.colliders.push({
      minX: PK.x - 28, maxX: PK.x + 28,
      minZ: PK.z - 13, maxZ: PK.z + 13,
      height: 7.5
    });
  }

  /** 8. Build Outdoor Cafeteria & Student Relaxation Garden */
  buildOutdoorCafeteria() {
    const cafeGroup = new THREE.Group();
    const CF = (this.campusData && this.campusData.canteen && this.campusData.canteen.outdoor) || { x: -75, z: -75 };
    cafeGroup.position.set(CF.x, 0, CF.z);

    // Terrace Stone Floor
    const floorGeo = new THREE.PlaneGeometry(54, 40);
    const floorMat = new THREE.MeshLambertMaterial({ color: 0xe2e8f0 });
    const floor = new THREE.Mesh(floorGeo, floorMat);
    floor.rotation.x = -Math.PI / 2;
    floor.position.y = 0.03;
    floor.receiveShadow = true;
    cafeGroup.add(floor);

    // Center Gazebo Pavilion
    const roof = new THREE.Mesh(
      new THREE.ConeGeometry(9.0, 4.5, 8),
      new THREE.MeshLambertMaterial({ color: 0x059669 })
    );
    roof.position.y = 8.0;
    roof.castShadow = true;
    cafeGroup.add(roof);

    // Gazebo Pillars
    for (let a = 0; a < Math.PI * 2; a += Math.PI / 2) {
      const p = new THREE.Mesh(new THREE.CylinderGeometry(0.35, 0.35, 6, 8), new THREE.MeshLambertMaterial({ color: 0x78350f }));
      p.position.set(Math.cos(a) * 6.5, 3.0, Math.sin(a) * 6.5);
      p.castShadow = true;
      cafeGroup.add(p);
    }

    // Outdoor Picnic Tables with Parasol Umbrellas
    const tableCoords = [
      [-16, 8, 0xef4444], [16, 8, 0x3b82f6], [-16, -8, 0xf59e0b], [16, -8, 0x10b981]
    ];
    tableCoords.forEach(([tx, tz, umbColor]) => {
      const tableGroup = new THREE.Group();
      tableGroup.position.set(tx, 0, tz);

      // Round Table
      const top = new THREE.Mesh(new THREE.CylinderGeometry(2.4, 2.4, 0.3, 16), new THREE.MeshLambertMaterial({ color: 0x78350f }));
      top.position.y = 2.0;
      top.castShadow = true;
      const leg = new THREE.Mesh(new THREE.CylinderGeometry(0.3, 0.3, 2.0, 8), new THREE.MeshLambertMaterial({ color: 0x1e293b }));
      leg.position.y = 1.0;
      tableGroup.add(top, leg);

      // Stools
      [[-2.6, 0], [2.6, 0], [0, -2.6], [0, 2.6]].forEach(([sx, sz]) => {
        const stool = new THREE.Mesh(new THREE.CylinderGeometry(0.8, 0.8, 1.2, 12), new THREE.MeshLambertMaterial({ color: 0x94a3b8 }));
        stool.position.set(sx, 0.6, sz);
        tableGroup.add(stool);
      });

      // Sun Parasol Umbrella
      const umbPole = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.12, 5.5, 8), new THREE.MeshLambertMaterial({ color: 0xe2e8f0 }));
      umbPole.position.y = 3.5;
      const umbrella = new THREE.Mesh(new THREE.ConeGeometry(4.0, 1.5, 12), new THREE.MeshLambertMaterial({ color: umbColor }));
      umbrella.position.y = 5.6;
      umbrella.castShadow = true;
      tableGroup.add(umbPole, umbrella);

      cafeGroup.add(tableGroup);
    });

    // Signboard
    const sign = this.createSignCanvas("☕ KANTIN & TAMAN TERBUKA", 26, 4.0);
    sign.position.set(0, 9.8, 14);
    cafeGroup.add(sign);

    this.scene.add(cafeGroup);

    // Colliders around central gazebo
    this.colliders.push({
      minX: CF.x - 7, maxX: CF.x + 7,
      minZ: CF.z - 7, maxZ: CF.z + 7,
      height: 6
    });
  }

  /** 9. Build Campus Perimeter Boundary Walls & Gate Pillars */
  buildCampusPerimeter() {
    const wallMat = new THREE.MeshLambertMaterial({ color: 0x334155 });
    const capMat = new THREE.MeshLambertMaterial({ color: 0x0f172a });
    const pillarMat = new THREE.MeshLambertMaterial({ color: 0x1e293b });

    const wallHeight = 3.2;
    const wallThick = 1.2;

    const sections = [
      // North Wall (Z = -175)
      { x: 0, z: -175, w: 460, d: wallThick },
      // South Wall Left & Right of Entrance (Z = 175)
      { x: -130, z: 175, w: 200, d: wallThick },
      { x: 130, z: 175, w: 200, d: wallThick },
      // West Wall (X = -245)
      { x: -245, z: 0, w: wallThick, d: 350 },
      // East Wall (X = 245, leaving gap at Gerbang Utama)
      { x: 245, z: -90, w: wallThick, d: 170 },
      { x: 245, z: 90, w: wallThick, d: 150 }
    ];

    sections.forEach(sec => {
      const wall = new THREE.Mesh(new THREE.BoxGeometry(sec.w, wallHeight, sec.d), wallMat);
      wall.position.set(sec.x, wallHeight / 2, sec.z);
      wall.castShadow = true;
      wall.receiveShadow = true;
      this.scene.add(wall);

      // Wall coping top trim
      const cap = new THREE.Mesh(new THREE.BoxGeometry(sec.w + 0.4, 0.4, sec.d + 0.4), capMat);
      cap.position.set(sec.x, wallHeight + 0.2, sec.z);
      this.scene.add(cap);

      // Add collider so player stays inside the large campus
      this.colliders.push({
        minX: sec.x - sec.w / 2 - 0.5,
        maxX: sec.x + sec.w / 2 + 0.5,
        minZ: sec.z - sec.d / 2 - 0.5,
        maxZ: sec.z + sec.d / 2 + 0.5,
        height: wallHeight + 2
      });
    });

    // Boundary Gate Pillars with Glowing Lamps
    const pillarCoords = [
      [-245, -175], [245, -175], [-245, 175], [245, 175],
      [-30, 175], [30, 175], [245, -5], [245, 15]
    ];
    pillarCoords.forEach(([px, pz]) => {
      const pil = new THREE.Mesh(new THREE.BoxGeometry(2.4, 5.0, 2.4), pillarMat);
      pil.position.set(px, 2.5, pz);
      pil.castShadow = true;
      this.scene.add(pil);

      // Lantern on top
      const lantern = new THREE.Mesh(new THREE.SphereGeometry(0.7, 10, 10), new THREE.MeshBasicMaterial({ color: 0xfef08a }));
      lantern.position.set(px, 5.5, pz);
      this.scene.add(lantern);
    });
  }

  buildInteractiveHotspots() {
    this.interactiveHotspots = [
      {
        id: 'perpus',
        name: 'Perpustakaan SMK Silber',
        desc: 'Pusat literasi, buku referensi, dan tugas siswa',
        pos: new THREE.Vector3(8, 0.2, -52),
        floor: 1,
        color: 0xca8a04,
        prompt: '📚 Masuk Perpustakaan & Cek Tugas',
        action: () => { window.location.href = '/tugas'; }
      },
      {
        id: 'toko',
        name: 'Mini Market Sekolah',
        desc: 'Toko perlengkapan sekolah, seragam, dan snack',
        pos: new THREE.Vector3(138, 0.2, 48),
        floor: 1,
        color: 0xdb2777,
        prompt: '🛒 Belanja di Toko Sekolah',
        action: () => { window.location.href = '/toko'; }
      },
      {
        id: 'futsal',
        name: 'Lapangan Olahraga & Futsal',
        desc: 'Pusat kegiatan ekstrakurikuler & event olahraga',
        pos: new THREE.Vector3(88, 0.2, 0),
        floor: 1,
        color: 0x16a34a,
        prompt: '🏆 Cek Event & Turnamen',
        action: () => { if (typeof openWorldEventDialog === 'function') openWorldEventDialog(); }
      },
      {
        id: 'lab_tik',
        name: 'Laboratorium Komputer & TIK',
        desc: 'Praktikum jaringan komputer, pemrograman & cyber security',
        pos: new THREE.Vector3(55, 0.2, -24),
        floor: 1,
        color: 0x0284c7,
        prompt: '🎯 Ambil Misi Praktikum Komputer',
        action: () => { if (typeof openWorldMissionDialog === 'function') openWorldMissionDialog(); }
      },
      {
        id: 'silber_ai',
        name: 'Pusat Informasi & AI Si Silber',
        desc: 'Konsultasi kurikulum, bantuan materi, dan panduan virtual city',
        pos: new THREE.Vector3(-20, 0.2, 62),
        floor: 1,
        color: 0x8b5cf6,
        prompt: '🤖 Tanya Si Silber AI Companion',
        action: () => { if (typeof openWorldAiDialog === 'function') openWorldAiDialog(); }
      },
      {
        id: 'fountain',
        name: 'Plaza & Air Mancur Sekolah',
        desc: 'Titik kumpul utama dan denah navigasi seluruh gedung',
        pos: new THREE.Vector3(-18, 0.2, 16),
        floor: 1,
        color: 0x06b6d4,
        prompt: '🗺️ Buka Peta Interaktif Sekolah',
        action: () => { if (typeof toggleMapModal === 'function') toggleMapModal(); }
      },
      {
        id: 'parkir',
        name: 'Parkiran Kendaraan Siswa',
        desc: 'Area parkir terpadu sepeda & motor siswa SMK Silber',
        pos: new THREE.Vector3(145, 0.2, -55),
        floor: 1,
        color: 0x0284c7,
        prompt: '🅿️ Cek Parkiran Kendaraan Siswa',
        action: () => { if (window.UI) UI.toast('Parkiran Kendaraan Siswa SMK Silber aman & tertib 🛵', 'info'); }
      },
      {
        id: 'kantin',
        name: 'Kantin & Taman Terbuka',
        desc: 'Area santai, tempat makan, dan gazebo siswa',
        pos: new THREE.Vector3(-75, 0.2, -75),
        floor: 1,
        color: 0x10b981,
        prompt: '☕ Kunjungi Kantin & Taman Terbuka',
        action: () => { if (window.UI) UI.toast('Kantin & Taman Terbuka SMK Silber ☕🥐', 'info'); }
      },
      {
        id: 'bank_mini',
        name: 'Bank Mini SMK Silber',
        desc: 'Simulasi transaksi, tabungan koin, dan literasi finansial',
        pos: new THREE.Vector3(46, 0.2, 0),
        floor: 1,
        color: 0x1d4ed8,
        prompt: '🏦 Bank Mini: Layanan Koin & Transfer',
        action: () => {
          if (typeof window.openBankMiniModal === 'function') {
            window.openBankMiniModal();
          } else {
            const coinsEl = document.getElementById('hudCoins'); const coins = (coinsEl && coinsEl.textContent) || '0';
            if (window.UI) UI.toast('Bank Mini SMK Silber: Saldo aktif Anda adalah ' + coins + ' Koin 🪙', 'info');
          }
        }
      },
      {
        id: 'musholah',
        name: 'Musholah Sekolah',
        desc: 'Pusat ibadah, pembinaan karakter, dan etika tata tertib',
        pos: new THREE.Vector3(-174, 0.2, 16),
        floor: 1,
        color: 0x16a34a,
        prompt: '🕌 Musholah: Etika & Tata Tertib',
        action: () => {
          if (window.UI) UI.toast('Musholah SMK Silber: Jagalah kesucian, ketertiban, dan sopan santun 🤲', 'info');
        }
      },
      {
        id: 'ruang_tu',
        name: 'Ruang Tata Usaha',
        desc: 'Layanan administrasi, surat pengantar, dan dokumen siswa',
        pos: new THREE.Vector3(-35, 0.2, 62),
        floor: 1,
        color: 0x0284c7,
        prompt: '📋 Ruang TU: Layanan Administrasi Siswa',
        action: () => {
          if (window.UI) UI.toast('Ruang TU: Layanan administrasi akademik siswa SMK Silber siap melayani 📄', 'info');
        }
      },
      {
        id: 'ruang_praktik',
        name: 'Ruang Praktik Siswa (RPS)',
        desc: 'Bengkel kejuruan, simulasi industri, dan praktik keahlian',
        pos: new THREE.Vector3(-100, 0.2, 50),
        floor: 1,
        color: 0x0f766e,
        prompt: '🔧 Ruang Praktik Siswa (RPS)',
        action: () => {
          if (typeof openWorldMissionDialog === 'function') openWorldMissionDialog();
          else if (window.UI) UI.toast('Ruang Praktik Siswa: Siap mengasah keterampilan teknis kejuruan 🛠️', 'info');
        }
      },
      {
        id: 'lab_rakitan',
        name: 'Lab. Rakitan Komputer',
        desc: 'Praktik perakitan PC, troubleshooting hardware, dan instalasi OS',
        pos: new THREE.Vector3(35, FLOOR_H + SLAB_T / 2, 62),
        floor: 2,
        color: 0x38bdf8,
        prompt: '🖥️ Lab Rakitan: Tantangan Hardware PC (Lantai 2 — naik tangga)',
        action: () => {
          if (typeof openWorldMissionDialog === 'function') openWorldMissionDialog();
          else if (window.UI) UI.toast('Lab Rakitan Komputer: Modul perakitan PC aktif 💻', 'info');
        }
      },
      {
        id: 'ruang_bk',
        name: 'Ruang BK / BKK',
        desc: 'Bimbingan konseling, konsultasi karier, dan penyaluran kerja industri',
        pos: new THREE.Vector3(-40, FLOOR_H + SLAB_T / 2, -24),
        floor: 2,
        color: 0xf59e0b,
        prompt: '💼 Ruang BK/BKK: Konseling & Karier (Lantai 2 — naik tangga)',
        action: () => {
          if (window.UI) UI.toast('Ruang BK & BKK: Konsultasi masa depan, bakat minat & karier industri 🎓', 'info');
        }
      }
    ];

    this.hotspotMeshes = [];
    // Koordinat hotspot ditulis dalam denah asli campus.json → ikut tata letak dunia (world3d-art.js).
    if (this.mapPoint) this.interactiveHotspots.forEach(h => { const q = this.mapPoint(h.pos.x, h.pos.z); h.pos.x = q.x; h.pos.z = q.z; });

    this.interactiveHotspots.forEach(h => {
      const group = new THREE.Group();
      group.position.copy(h.pos);

      // Glowing pulsing ring on ground
      const ringGeo = new THREE.RingGeometry(2.8, 3.6, 24);
      const ringMat = new THREE.MeshBasicMaterial({
        color: h.color,
        side: THREE.DoubleSide,
        transparent: true,
        opacity: 0.75
      });
      const ring = new THREE.Mesh(ringGeo, ringMat);
      ring.rotation.x = -Math.PI / 2;
      group.add(ring);

      // Floating rotating crystal / diamond beacon
      const beaconGeo = new THREE.OctahedronGeometry(1.2, 0);
      const beaconMat = new THREE.MeshLambertMaterial({
        color: h.color,
        emissive: h.color,
        emissiveIntensity: 0.6
      });
      const beacon = new THREE.Mesh(beaconGeo, beaconMat);
      beacon.position.y = 3.6;
      group.add(beacon);

      // Soft vertical light pillar beam
      const beamGeo = new THREE.CylinderGeometry(0.35, 1.2, 8, 12, 1, true);
      const beamMat = new THREE.MeshBasicMaterial({
        color: h.color,
        transparent: true,
        opacity: 0.28,
        side: THREE.DoubleSide
      });
      const beam = new THREE.Mesh(beamGeo, beamMat);
      beam.position.y = 4.0;
      group.add(beam);

      this.scene.add(group);
      this.hotspotMeshes.push({ group, ring, beacon, data: h, animTime: Math.random() * Math.PI });
    });
  }

  updateHotspots(delta) {
    if (!this.hotspotMeshes || !this.playerPos) return;

    let nearest = null;
    let minDist = 9999;

    this.hotspotMeshes.forEach(hm => {
      hm.group.visible = !this.isHiddenByLevel(hm.data.pos);
      hm.animTime += delta * 2.2;
      hm.beacon.rotation.y += delta * 1.5;
      hm.beacon.position.y = 3.6 + Math.sin(hm.animTime) * 0.45;
      hm.ring.scale.setScalar(1.0 + Math.sin(hm.animTime * 1.5) * 0.12);

      const d = this.playerPos.distanceTo(hm.data.pos);
      if (d < minDist && Math.abs(hm.data.pos.y - this.playerPos.y) < FLOOR_H / 2) {
        minDist = d;
        nearest = hm.data;
      }
    });

    this.nearestHotspot = (minDist <= 10.0) ? nearest : null;

    // Kursi: "🪑 Duduk" bila ada kursi terdekat (lebih dekat dari hotspot); saat duduk → "Berdiri".
    if (this.isSitting) {
      this.nearestHotspot = { id: 'seat-stand', name: 'Kursi', prompt: '🧍 Berdiri', pos: this.playerPos.clone(), action: () => this.standUp() };
    } else if (this.seats.length) {
      let best = null, bestD = 3.2;
      for (const st of this.seats) {
        if (!st.world) { st.world = new THREE.Vector3(); st.obj.getWorldPosition(st.world); }
        if (Math.abs(st.world.y - this.playerPos.y) > 3) continue;
        const dd = Math.hypot(st.world.x - this.playerPos.x, st.world.z - this.playerPos.z);
        if (dd < bestD && this.isDeepVisible(st.obj)) { bestD = dd; best = st; }
      }
      if (best && (!this.nearestHotspot || bestD < minDist)) {
        this.nearestHotspot = { id: 'seat', name: 'Kursi', prompt: '🪑 Duduk', pos: best.world.clone(), action: () => this.sitOn(best) };
      }
    }

    // Objek tambahan dari skrip lain (world-life.js: kucing, sampah) — { name, prompt, pos, dist, action }.
    if (!this.isSitting && typeof this.extraTargets === 'function') {
      try {
        const ex = this.extraTargets(this.playerPos);
        if (ex && (!this.nearestHotspot || ex.dist < this.playerPos.distanceTo(this.nearestHotspot.pos))) {
          this.nearestHotspot = { id: 'extra', name: ex.name, prompt: ex.prompt, pos: ex.pos, action: ex.action };
        }
      } catch (e) { this.extraTargets = null; }
    }

    // Integrasi Modul Game (Workstream E): lokasi terdekat (nama hotspot) untuk LiveChat.
    if (nearest && window.LiveChat) {
      const n = nearest.name || 'Kampus';
      if (this.__vcLocName !== n) { this.__vcLocName = n; LiveChat.setContext({ locationName: n }); }
    }

    const promptEl = document.getElementById('hotspotPrompt');
    const promptTxt = document.getElementById('hotspotPromptText');
    const mobileActBtn = document.getElementById('btnMobileAction');
    if (promptEl && promptTxt) {
      if (this.nearestHotspot) {
        promptTxt.textContent = this.nearestHotspot.prompt;
        promptEl.style.display = 'flex';
        if (mobileActBtn) {
          mobileActBtn.style.display = 'flex';
          mobileActBtn.textContent = '✋ ' + (this.nearestHotspot.prompt.length > 10 ? 'Aksi' : this.nearestHotspot.prompt);
        }
      } else {
        promptEl.style.display = 'none';
        if (mobileActBtn) mobileActBtn.style.display = 'none';
      }
    }

    // Direct objective compass towards nearest hotspot or objective
    const compassTarget = this.navTarget ? this.navTarget : (this.nearestHotspot ? this.nearestHotspot.pos : (nearest ? nearest.pos : null));
    if (compassTarget) {
      const dx = compassTarget.x - this.playerPos.x;
      const dz = compassTarget.z - this.playerPos.z;
      const targetAngle = Math.atan2(dx, dz);
      const relativeAngle = targetAngle - this.headingAngle;
      const needle = document.getElementById('compassNeedle');
      if (needle) {
        needle.style.transform = `rotate(${relativeAngle * 180 / Math.PI}deg)`;
      }
    }
  }

  isDeepVisible(o) {
    for (let n = o; n; n = n.parent) if (!n.visible) return false;
    return true;
  }

  /** Duduk: kaki ke depan, pinggul di atas dudukan, menghadap +z (sandaran semua kursi di −z). */
  sitOn(seat) {
    if (!seat || !seat.world) return;
    const ud0 = (this.player && this.player.userData) || {};
    const HIP = (ud0.hipY || 3.6) * AVATAR_EFFECTIVE_SCALE;        // pivot kaki model × skala avatar (model rig: 1.75)
    const LEG_HALF = (ud0.legR || 0.75) * AVATAR_EFFECTIVE_SCALE;  // setengah tebal kaki saat horizontal
    this.isSitting = true;
    this.sitSeat = seat;
    this.playerPos.set(seat.world.x, seat.world.y + seat.top - HIP + LEG_HALF, seat.world.z);
    this.playerVelocity.set(0, 0, 0);
    this.headingAngle = 0;
    this.playTone(420, 'sine', 0.08);
  }

  standUp() {
    if (!this.isSitting) return;
    this.isSitting = false;
    const s = this.sitSeat; this.sitSeat = null;
    if (s && s.world) { this.playerPos.x = s.world.x; this.playerPos.z = s.world.z + 1.6; } // keluar ke depan kursi
    this.playerPos.y = this.groundHeightAt(this.playerPos.x, this.playerPos.z, this.playerPos.y + 2);
    this.isGrounded = true;
    if (this.leftLeg) this.leftLeg.rotation.x = 0;
    if (this.rightLeg) this.rightLeg.rotation.x = 0;
  }

  interactNearestHotspot() {
    if (this.nearestHotspot && (this.nearestHotspot.id === 'seat' || this.nearestHotspot.id === 'seat-stand')) {
      this.nearestHotspot.action();
      return true;
    }
    // Integrasi Modul Game (Workstream C): hotspot dengan misi membuka mini-game.
    if (this.nearestHotspot && window.MiniGames && HOTSPOT_MISSION_IDS[this.nearestHotspot.id]) {
      this.playTone(680, 'sine', 0.12);
      vc3dOpenMissionMinigame(HOTSPOT_MISSION_IDS[this.nearestHotspot.id]);
      return true;
    }
    if (this.nearestHotspot && typeof this.nearestHotspot.action === 'function') {
      this.playTone(680, 'sine', 0.12);
      this.nearestHotspot.action();
      return true;
    }
    return false;
  }

  /**
   * Karakter 3D dari konfigurasi kustom v2 (sama dengan dashboard & Dunia Pixel, dinormalisasi
   * oleh PixelAvatar.normalize). Bentuk membulat: kepala bola, badan silinder meruncing, sendi &
   * tangan bola, sepatu oval — bukan kotak. Pivot lengan/kaki & userData tetap (animasi lama jalan).
   */
  buildStylizedAvatar(rawCfg = {}, isRemote = false) {
    const PA = window.PixelAvatar;
    const cfg = PA ? PA.normalize(rawCfg) : { skin: '#e2ad84', hairStyle: 'short', hair: '#2b1d16', outfit: 'casual', top: '#1d4ed8', bottom: '#1e3a8a', shoes: '#111827', acc: [], accColor: '#2f8f4e' };
    const group = new THREE.Group();
    const col = (h, f) => { const c = new THREE.Color(h); if (f) c.multiplyScalar(f); return c; };
    const mat = (h, f) => new THREE.MeshLambertMaterial({ color: col(h, f) });
    const has = (a) => cfg.acc.indexOf(a) >= 0;
    const of = cfg.outfit;
    let top = cfg.top, bot = cfg.bottom;
    if (of === 'tjkt') { top = '#1d4ed8'; bot = '#1e3a8a'; }
    else if (of === 'putih_abu') { top = '#f5f5f4'; bot = '#6b7280'; }
    else if (of === 'pramuka') { top = '#b08d57'; bot = '#4a3726'; }
    else if (of === 'almamater') { top = '#1e3a8a'; }
    else if (of === 'jas_lab' || of === 'koki') { top = '#f8fafc'; }
    else if (of === 'kerja') { top = '#d6c7a1'; }
    const skinM = mat(cfg.skin), hairM = mat(cfg.hair), pantsM = mat(bot), shoeM = mat(of === 'olahraga' ? '#f5f5f5' : cfg.shoes);
    let topM = mat(top);
    if (of === 'batik') {
      // pola batik sederhana (kanvas kecil, dipakai ulang)
      if (!this.__batikTex) this.__batikTex = {};
      if (!this.__batikTex[top]) {
        const cv = document.createElement('canvas'); cv.width = cv.height = 32;
        const g = cv.getContext('2d');
        g.fillStyle = top; g.fillRect(0, 0, 32, 32);
        g.fillStyle = '#d9b44a';
        for (let y = 0; y < 32; y += 8) for (let x = (y / 8) % 2 ? 4 : 0; x < 32; x += 8) { g.fillRect(x + 2, y + 1, 3, 3); g.fillRect(x + 3, y, 1, 5); g.fillRect(x + 1, y + 2, 5, 1); }
        g.fillStyle = 'rgba(0,0,0,0.25)'; for (let y = 4; y < 32; y += 8) g.fillRect(0, y, 32, 1);
        const t = new THREE.CanvasTexture(cv); t.wrapS = t.wrapT = THREE.RepeatWrapping; t.repeat.set(2, 2); t.magFilter = THREE.NearestFilter;
        this.__batikTex[top] = t;
      }
      topM = new THREE.MeshLambertMaterial({ map: this.__batikTex[top] });
    }
    const add = (geo, m, x, y, z, parent) => { const o = new THREE.Mesh(geo, m); o.position.set(x, y, z); o.castShadow = true; (parent || group).add(o); return o; };

    // Kepala & wajah
    const head = add(new THREE.SphereGeometry(1.35, 18, 14), skinM, 0, 6.35, 0);
    head.scale.set(1, 1.03, 0.95);
    add(new THREE.CylinderGeometry(0.42, 0.5, 0.6, 10), skinM, 0, 5.05, 0);
    const face = document.createElement('canvas'); face.width = face.height = 64;
    const fc = face.getContext('2d');
    fc.fillStyle = '#1b1020'; fc.fillRect(17, 24, 7, 10); fc.fillRect(40, 24, 7, 10);
    fc.fillStyle = '#ffffff'; fc.fillRect(19, 25, 3, 3); fc.fillRect(42, 25, 3, 3);
    fc.fillStyle = 'rgba(232,120,120,0.45)'; fc.fillRect(11, 37, 8, 4); fc.fillRect(45, 37, 8, 4);
    fc.fillStyle = '#9a4a3a'; fc.fillRect(27, 43, 10, 2); fc.fillRect(25, 41, 2, 2); fc.fillRect(37, 41, 2, 2);
    if (has('glasses')) { fc.strokeStyle = '#1c1c24'; fc.lineWidth = 2.5; fc.strokeRect(13, 21, 15, 15); fc.strokeRect(36, 21, 15, 15); fc.fillStyle = '#1c1c24'; fc.fillRect(28, 26, 8, 2); }
    const faceTex = new THREE.CanvasTexture(face); faceTex.magFilter = THREE.NearestFilter;
    const facePlane = add(new THREE.PlaneGeometry(1.95, 1.95), new THREE.MeshBasicMaterial({ map: faceTex, transparent: true }), 0, 6.3, 1.31);
    facePlane.castShadow = false;
    facePlane.userData.isOutline = true; // jangan beri garis tepi

    // Rambut
    const hs = cfg.hairStyle;
    const capGeo = new THREE.SphereGeometry(1.43, 18, 10, 0, Math.PI * 2, 0, Math.PI * 0.5);
    if (hs === 'hijab') {
      const hj = add(new THREE.SphereGeometry(1.5, 18, 14, Math.PI * 0.72, Math.PI * 1.56), hairM, 0, 6.3, -0.05);
      hj.scale.set(1, 1.05, 1);
      add(new THREE.CylinderGeometry(1.25, 2.05, 1.7, 16), hairM, 0, 4.55, -0.05);
    } else {
      const cap = add(capGeo, hairM, 0, 6.45, -0.04); cap.scale.set(1, 0.9, 1);
      add(new THREE.SphereGeometry(1.38, 16, 10, Math.PI * 0.5, Math.PI, 0, Math.PI * 0.62), hairM, 0, 6.3, -0.08); // belakang kepala
      const hatOn = has('cap') || has('chef_hat'); // topi menutup rambut atas
      if (hs === 'spiky' && !hatOn) for (let i = 0; i < 6; i++) { const sp = add(new THREE.ConeGeometry(0.32, 0.9, 6), hairM, Math.cos(i) * 0.7, 7.75, Math.sin(i * 1.7) * 0.6); sp.rotation.z = Math.cos(i) * -0.4; }
      if (hs === 'messy' && !hatOn) for (let i = 0; i < 5; i++) add(new THREE.SphereGeometry(0.42, 8, 6), hairM, -0.9 + i * 0.45, 7.55 - (i % 2) * 0.15, 0.6 - (i % 3) * 0.3);
      if (hs === 'curly') for (let i = 0; i < (hatOn ? 8 : 12); i++) { const ang = i / (hatOn ? 8 : 12) * Math.PI * 2; if (hatOn && Math.sin(ang) > 0.3) continue; add(new THREE.SphereGeometry(0.48, 8, 6), hairM, Math.cos(ang) * 1.3, hatOn ? 6.25 : 7.15 + (i % 2) * 0.25, Math.sin(ang) * 1.25 - 0.1); }
      if (hs === 'long') { const back = add(new THREE.CylinderGeometry(1.35, 1.15, 2.6, 14, 1, true, Math.PI * 0.6, Math.PI * 0.8), hairM, 0, 5.2, 0); back.material = hairM; }
      if (hs === 'ponytail') { const tail = add(new THREE.ConeGeometry(0.5, 2.2, 10), hairM, 0, 5.9, -1.65); tail.rotation.x = -Math.PI * 0.85; }
      if (hs === 'bun' && !hatOn) add(new THREE.SphereGeometry(0.62, 12, 10), hairM, 0, 7.75, -0.6);
      if (hs === 'short' || hs === 'messy') { const fr = add(new THREE.SphereGeometry(0.5, 8, 6), hairM, -0.55, 7.25, 1.0); fr.scale.set(1.4, 0.6, 0.6); }
    }
    if (of === 'hoodie') { const hood = add(new THREE.SphereGeometry(1.15, 14, 10, 0, Math.PI * 2, 0, Math.PI * 0.6), topM, 0, 5.15, -1.05); hood.rotation.x = -0.6; }

    // Badan (silinder meruncing, oval)
    const coatLong = of === 'jas_lab';
    const torso = add(new THREE.CylinderGeometry(1.45, 1.22, 3.3, 16), topM, 0, 3.25, 0);
    torso.scale.z = 0.72;
    add(new THREE.CylinderGeometry(1.24, 1.2, 0.8, 16), coatLong ? topM : pantsM, 0, 1.55, 0).scale.z = 0.74;
    if (coatLong) { const tail = add(new THREE.CylinderGeometry(1.3, 1.45, 1.4, 16, 1, true), topM, 0, 0.95, 0); tail.scale.z = 0.8; }
    // bahu membulat (TJKT: putih)
    const shoulderM = of === 'tjkt' ? mat('#f5f5f5') : topM;
    add(new THREE.SphereGeometry(0.62, 12, 10), shoulderM, -1.48, 4.6, 0);
    add(new THREE.SphereGeometry(0.62, 12, 10), shoulderM, 1.48, 4.6, 0);
    const front = (geo, m, x, y, z) => add(geo, m, x, y, z === undefined ? 0.92 : z);
    const flat = (w, h) => new THREE.BoxGeometry(w, h, 0.08);
    if (of === 'tjkt') { const collar = add(new THREE.TorusGeometry(0.62, 0.16, 8, 16), mat('#2f8f4e'), 0, 4.82, 0.05); collar.rotation.x = Math.PI / 2; front(flat(0.18, 2.6), mat('#93c5fd'), 0, 3.3); front(flat(0.4, 0.4), mat('#facc15'), 0.62, 3.9, 0.88); }
    else if (of === 'putih_abu') { if (!has('tie')) front(flat(0.32, 1.9), mat('#6b7280'), 0, 3.6); front(flat(0.3, 0.3), mat('#c0392b'), 0.65, 3.95, 0.88); }
    else if (of === 'pramuka') { const sc = add(new THREE.ConeGeometry(0.6, 1.0, 3), mat('#c0392b'), 0, 4.35, 0.85); sc.rotation.x = Math.PI; }
    else if (of === 'olahraga') { add(flat(0.14, 3.2), mat('#ffffff'), -1.32, 3.25, 0.35).rotation.y = -1.1; add(flat(0.14, 3.2), mat('#ffffff'), 1.32, 3.25, 0.35).rotation.y = 1.1; front(flat(0.7, 0.5), mat('#ffffff'), 0, 3.9); }
    else if (of === 'hoodie') { front(flat(1.4, 0.7), mat(top, 0.8), 0, 2.55); front(flat(0.08, 0.7), mat('#f5f5f5'), -0.3, 4.3); front(flat(0.08, 0.7), mat('#f5f5f5'), 0.3, 4.3); }
    else if (of === 'almamater') { front(flat(0.7, 1.9), mat('#f5f5f5'), 0, 3.95); front(flat(0.18, 2.2), mat('#152a66'), -0.45, 3.7, 0.94); front(flat(0.18, 2.2), mat('#152a66'), 0.45, 3.7, 0.94); front(flat(0.32, 0.32), mat('#d9b44a'), 0.75, 3.9, 0.9); }
    else if (of === 'jas_lab') { front(flat(0.6, 2.1), mat(cfg.top), 0, 3.8); }
    else if (of === 'koki') { for (let i = 0; i < 3; i++) { front(new THREE.SphereGeometry(0.1, 6, 4), mat('#9aa1ab'), -0.35, 4.1 - i * 0.6, 0.93); front(new THREE.SphereGeometry(0.1, 6, 4), mat('#9aa1ab'), 0.35, 4.1 - i * 0.6, 0.93); } const kc = add(new THREE.TorusGeometry(0.62, 0.14, 8, 16), mat('#c0392b'), 0, 4.82, 0.05); kc.rotation.x = Math.PI / 2; }
    else if (of === 'kerja') { front(flat(1.6, 2.6), mat('#8a6d3b'), 0, 2.6, 0.94); }
    else { front(flat(0.6, 0.8), mat(cfg.accColor), 0, 4.2); }

    // Lengan (pivot sama dengan versi lama)
    const longSleeve = ['jas_lab', 'almamater', 'hoodie', 'batik', 'koki', 'pramuka'].indexOf(of) >= 0;
    const makeArm = (side) => {
      const pivot = new THREE.Group();
      pivot.position.set(side * 1.78, 4.6, 0);
      add(new THREE.CylinderGeometry(0.44, 0.38, 1.6, 10), of === 'tjkt' ? mat('#f5f5f5') : topM, 0, -0.75, 0, pivot);
      if (of === 'tjkt') add(new THREE.CylinderGeometry(0.4, 0.4, 0.22, 10), mat('#2f8f4e'), 0, -1.45, 0, pivot);
      add(new THREE.CylinderGeometry(0.36, 0.32, 1.35, 10), longSleeve ? topM : skinM, 0, -2.1, 0, pivot);
      add(new THREE.SphereGeometry(0.38, 10, 8), skinM, 0, -2.9, 0, pivot);
      if (side < 0 && has('watch')) { const w = add(new THREE.TorusGeometry(0.36, 0.09, 6, 12), mat('#1c1c24'), 0, -2.55, 0, pivot); w.rotation.x = Math.PI / 2; }
      group.add(pivot);
      return pivot;
    };
    const leftArmPivot = makeArm(-1), rightArmPivot = makeArm(1);

    // Kaki & sepatu oval
    const makeLeg = (side) => {
      const pivot = new THREE.Group();
      pivot.position.set(side * 0.62, 2.0, 0);
      add(new THREE.CylinderGeometry(0.52, 0.42, 1.75, 10), pantsM, 0, -0.85, 0, pivot);
      const shoe = add(new THREE.SphereGeometry(0.55, 12, 8), shoeM, 0, -1.78, 0.18, pivot);
      shoe.scale.set(1, 0.55, 1.45);
      group.add(pivot);
      return pivot;
    };
    const leftLegPivot = makeLeg(-1), rightLegPivot = makeLeg(1);

    // Aksesoris
    const accM = mat(cfg.accColor);
    if (has('backpack')) { const bp = add(new THREE.CylinderGeometry(1.0, 1.05, 2.5, 14), accM, 0, 3.35, -1.15); bp.scale.z = 0.55; add(new THREE.SphereGeometry(1.0, 12, 8, 0, Math.PI * 2, 0, Math.PI / 2), accM, 0, 4.55, -1.15).scale.z = 0.55; add(flat(0.22, 2.4), mat(cfg.accColor, 0.7), -0.75, 3.6, 0.9); add(flat(0.22, 2.4), mat(cfg.accColor, 0.7), 0.75, 3.6, 0.9); }
    if (has('tie')) front(new THREE.ConeGeometry(0.24, 1.9, 4), mat(of === 'putih_abu' ? '#6b7280' : '#c0392b'), 0, 3.7, 0.95).rotation.x = Math.PI;
    if (has('lanyard')) { const cord = add(new THREE.TorusGeometry(0.75, 0.05, 6, 16, Math.PI), mat('#1d4ed8'), 0, 4.55, 0.82); cord.rotation.z = Math.PI; front(flat(0.6, 0.8), mat('#ffffff'), 0, 3.55, 0.97); }
    if (has('cap')) { const cp = add(new THREE.SphereGeometry(1.48, 18, 10, 0, Math.PI * 2, 0, Math.PI * 0.5), accM, 0, 6.55, -0.02); cp.scale.y = 0.75; const brim = add(new THREE.CylinderGeometry(0.95, 0.95, 0.12, 16, 1, false, -Math.PI * 0.5, Math.PI), accM, 0, 6.65, 0.95); brim.scale.z = 0.9; }
    if (has('chef_hat')) { add(new THREE.CylinderGeometry(1.15, 1.2, 0.9, 16), mat('#ffffff'), 0, 7.6, 0); add(new THREE.SphereGeometry(1.35, 14, 10), mat('#ffffff'), 0, 8.45, 0).scale.y = 0.65; }
    if (has('headphones')) { const band = add(new THREE.TorusGeometry(1.45, 0.14, 8, 18, Math.PI), mat('#1c1c24'), 0, 6.45, 0); band.rotation.y = Math.PI / 2; [-1, 1].forEach((s2) => { const cup = add(new THREE.CylinderGeometry(0.5, 0.5, 0.35, 12), accM, s2 * 1.45, 6.3, 0); cup.rotation.z = Math.PI / 2; }); }

    const AVATAR_SCALE = AVATAR_EFFECTIVE_SCALE;
    group.scale.setScalar(AVATAR_SCALE);
    group.userData = { head, torso, leftArm: leftArmPivot, rightArm: rightArmPivot, leftLeg: leftLegPivot, rightLeg: rightLegPivot, avatarScale: AVATAR_SCALE, config: cfg };
    return group;
  }

  createPlayerAvatar() {
    if (this.player) {
      this.scene.remove(this.player);
    }

    this.player = this.buildStylizedAvatar(this.avatarConfig || this.options.avatarConfig || {});
    this.toonify(this.player);
    this.addAvatarOutline(this.player);
    this.player.position.copy(this.playerPos);
    this.player.rotation.y = this.headingAngle;
    this.scene.add(this.player);

    this.playerHead = this.player.userData.head;
    this.playerTorso = this.player.userData.torso;
    this.leftArm = this.player.userData.leftArm;
    this.rightArm = this.player.userData.rightArm;
    this.leftLeg = this.player.userData.leftLeg;
    this.rightLeg = this.player.userData.rightLeg;
  }

  setAvatarConfig(cfg = {}) {
    this.avatarConfig = cfg;
    this.createPlayerAvatar();
  }

  async loadActiveAvatar() {
    try {
      const res = await fetch('/api/character/presets');
      if (!res.ok) return;
      const d = await res.json();
      if (d && d.characters) {
        const active = d.characters.find(c => c.is_active);
        if (active && active.config) {
          this.setAvatarConfig(active.config);
        }
      }
    } catch(e) {}
  }

  createNameplate(name, role) {
    const canvas = document.createElement('canvas');
    canvas.width = 256;
    canvas.height = 64;
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = 'rgba(15, 23, 42, 0.85)';
    if (ctx.roundRect) ctx.roundRect(8, 8, 240, 48, 12);
    else ctx.fillRect(8, 8, 240, 48);
    ctx.fill();

    ctx.strokeStyle = '#22d3ee';
    ctx.lineWidth = 2;
    ctx.stroke();

    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 20px system-ui, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(name, 128, 38);

    const tex = new THREE.CanvasTexture(canvas);
    const mat = new THREE.MeshBasicMaterial({ map: tex, transparent: true, side: THREE.DoubleSide });
    const plane = new THREE.Mesh(new THREE.PlaneGeometry(5, 1.25), mat);
    return plane;
  }

  peerDisplayName(p) {
    return (p && (p.fullName || p.username)) || 'Pemain'; // nama akun, bukan username teknis
  }

  /** Indikator speaker hijau di samping nameplate saat pemain sedang bicara (voice chat). */
  setPeerSpeaking(id, on) {
    const rec = this.remotePlayers && this.remotePlayers.get(id);
    if (!rec || !rec.nameplate) return;
    if (!rec.speakIcon) {
      if (!on) return;
      const c = document.createElement('canvas');
      c.width = 64; c.height = 64;
      const g = c.getContext('2d');
      g.fillStyle = '#16a34a';
      g.beginPath(); g.arc(32, 32, 30, 0, Math.PI * 2); g.fill();
      g.font = '34px system-ui, sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle';
      g.fillText('🔊', 32, 35);
      const mat = new THREE.MeshBasicMaterial({ map: new THREE.CanvasTexture(c), transparent: true, side: THREE.DoubleSide, depthTest: false });
      rec.speakIcon = new THREE.Mesh(new THREE.PlaneGeometry(1.1, 1.1), mat);
      rec.speakIcon.position.set(3.1, 0, 0.01);
      rec.speakIcon.renderOrder = 10;
      rec.nameplate.add(rec.speakIcon);
    }
    rec.speakIcon.visible = !!on;
  }

  /** Balon chat singkat di atas kepala avatar pemain lain (hilang setelah 5 detik). */
  showPeerChat(id, text) {
    const rec = this.remotePlayers && this.remotePlayers.get(id);
    if (!rec || !rec.nameplate || !text) return;
    if (rec.chatBubble) {
      rec.nameplate.remove(rec.chatBubble);
      rec.chatBubble.material.map.dispose();
      rec.chatBubble.material.dispose();
      rec.chatBubble.geometry.dispose();
      rec.chatBubble = null;
    }
    clearTimeout(rec.chatTimer);
    const msg = String(text).length > 48 ? String(text).slice(0, 47) + '…' : String(text);
    const c = document.createElement('canvas');
    c.width = 512; c.height = 96;
    const g = c.getContext('2d');
    g.fillStyle = 'rgba(255,255,255,0.95)';
    if (g.roundRect) { g.beginPath(); g.roundRect(4, 4, 504, 72, 18); g.fill(); } else g.fillRect(4, 4, 504, 72);
    g.beginPath(); g.moveTo(240, 76); g.lineTo(272, 76); g.lineTo(256, 94); g.fill();
    g.fillStyle = '#0f172a'; g.font = 'bold 26px system-ui, sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle';
    g.fillText(msg, 256, 41, 488);
    const mat = new THREE.MeshBasicMaterial({ map: new THREE.CanvasTexture(c), transparent: true, side: THREE.DoubleSide, depthTest: false });
    const bubble = new THREE.Mesh(new THREE.PlaneGeometry(6, 1.125), mat);
    bubble.position.set(0, 1.35, 0.01);
    bubble.renderOrder = 10;
    rec.nameplate.add(bubble);
    rec.chatBubble = bubble;
    rec.chatTimer = setTimeout(() => {
      if (rec.chatBubble !== bubble) return;
      rec.nameplate.remove(bubble);
      mat.map.dispose(); mat.dispose(); bubble.geometry.dispose();
      rec.chatBubble = null;
    }, 5000);
  }

  /**
   * prune=true hanya untuk DAFTAR LENGKAP (init WS / sinkron HTTP). Dulu update satu pemain
   * (player_join / player_move pemain baru / avatar_change) ikut menghapus SEMUA pemain lain
   * → avatar teman berkedip hilang-muncul begitu ada ≥3 siswa di room.
   */
  syncRemotePlayers(players = [], prune = true) {
    if (!Array.isArray(players)) return;
    if (!this.remotePlayers) this.remotePlayers = new Map();

    const activeIds = new Set();

    players.forEach(p => {
      if (!p || !p.id) return;
      activeIds.add(p.id);

      let record = this.remotePlayers.get(p.id);
      const initialY = Number.isFinite(Number(p.y)) ? Number(p.y) : 0;
      if (!record) {
        const peerMesh = this.buildStylizedAvatar(p.avatarConfig || {}, true);
        this.toonify(peerMesh);
        this.addAvatarOutline(peerMesh);
        peerMesh.position.set(p.x || 160, initialY, p.z || 12);

        const plate = this.createNameplate(this.peerDisplayName(p), p.role || 'student');
        plate.position.set(0, 8.8, 0); // ~1.76 unit dunia, pas di atas kepala chibi (~1.6 unit)
        plate.scale.setScalar(1.05);   // avatar kini skala 0.6 → plate ±5 unit lebar, terbaca dari kamera
        peerMesh.add(plate);

        this.scene.add(peerMesh);
        record = {
          id: p.id,
          data: p,
          mesh: peerMesh,
          nameplate: plate,
          floor: p.floor || 1,
          locationName: p.locationName || 'Kampus',
          lastConfig: JSON.stringify(p.avatarConfig || {}),
          targetPos: new THREE.Vector3(p.x || 160, initialY, p.z || 12),
          animTime: 0
        };
        peerMesh.userData.isPeerAvatar = true;
        peerMesh.userData.record = record;
        this.remotePlayers.set(p.id, record);
      } else {
        record.data = Object.assign(record.data || {}, p);
        if (p.floor !== undefined) record.floor = p.floor;
        if (p.locationName) record.locationName = p.locationName;
        const cfgStr = JSON.stringify(p.avatarConfig || {});
        if (record.lastConfig !== cfgStr) {
          this.scene.remove(record.mesh);
          const newMesh = this.buildStylizedAvatar(p.avatarConfig || {}, true);
          this.toonify(newMesh);
          this.addAvatarOutline(newMesh);
          newMesh.position.copy(record.mesh.position);
          const plate = this.createNameplate(this.peerDisplayName(p), p.role || 'student');
          plate.position.set(0, 8.8, 0);
          plate.scale.setScalar(1.05);
          newMesh.add(plate);
          if (record.speakIcon) plate.add(record.speakIcon);
          if (record.chatBubble) plate.add(record.chatBubble);
          record.nameplate = plate;
          this.scene.add(newMesh);
          record.mesh = newMesh;
          newMesh.userData.isPeerAvatar = true;
          newMesh.userData.record = record;
          record.lastConfig = cfgStr;
        }

        const targetY = Number.isFinite(Number(p.y)) ? Number(p.y) : record.targetPos.y;
        record.targetPos.set(p.x || 160, targetY, p.z || 12);
      }
    });

    if (!prune) return;
    for (const [id, record] of this.remotePlayers.entries()) {
      if (!activeIds.has(id)) {
        this.scene.remove(record.mesh);
        this.remotePlayers.delete(id);
      }
    }
  }

  updateRemotePlayers(delta) {
    if (!this.remotePlayers) return;
    let nearestPeer = null;
    let minPeerDist = 9999;

    for (const record of this.remotePlayers.values()) {
      const mesh = record.mesh;
      const prevX = mesh.position.x;
      const prevZ = mesh.position.z;
      mesh.position.lerp(record.targetPos, 0.25);

      // Floor visibility check
      if (true) {
        // Sembunyikan hanya bila berada di lantai gedung yang sedang disembunyikan (di atas pemain).
        mesh.visible = !this.isHiddenByLevel(mesh.position);
      }

      // Check distance for player interaction
      if (mesh.visible) {
        const peerDist = this.playerPos.distanceTo(mesh.position);
        if (peerDist < minPeerDist) {
          minPeerDist = peerDist;
          nearestPeer = record;
        }
      }

      const dx = mesh.position.x - prevX;
      const dz = mesh.position.z - prevZ;
      const speed = Math.sqrt(dx * dx + dz * dz);

      const ud = mesh.userData;
      if (speed > 0.02) {
        mesh.rotation.y = vcLerpAngle(mesh.rotation.y, Math.atan2(dx, dz), Math.min(1, delta * 12));
        record.animTime += delta * 12;
        const swing = Math.sin(record.animTime) * 0.55;
        if (ud.leftArm) ud.leftArm.rotation.x = -swing;
        if (ud.rightArm && (!record.emoteTime || record.emoteTime <= 0)) ud.rightArm.rotation.x = swing;
        if (ud.leftLeg) ud.leftLeg.rotation.x = swing;
        if (ud.rightLeg) ud.rightLeg.rotation.x = -swing;
        if (ud.torso) ud.torso.rotation.z = Math.sin(record.animTime) * 0.045;
      } else {
        const ease = Math.min(1, delta * 9);
        if (ud.leftArm) ud.leftArm.rotation.x += (0 - ud.leftArm.rotation.x) * ease;
        if (ud.rightArm && (!record.emoteTime || record.emoteTime <= 0)) ud.rightArm.rotation.x += (0 - ud.rightArm.rotation.x) * ease;
        if (ud.leftLeg) ud.leftLeg.rotation.x += (0 - ud.leftLeg.rotation.x) * ease;
        if (ud.rightLeg) ud.rightLeg.rotation.x += (0 - ud.rightLeg.rotation.x) * ease;
        if (ud.torso) ud.torso.rotation.z += (0 - ud.torso.rotation.z) * ease;
      }

      // Billboard nameplate so it always faces the camera
      if (record.nameplate && this.camera) {
        record.nameplate.rotation.y = -mesh.rotation.y + (this.camYaw - Math.PI);
      }

      // Remote player emote wave animation
      if (record.emoteTime && record.emoteTime > 0) {
        record.emoteTime = Math.max(0, record.emoteTime - delta);
        record.emotePhase = (record.emotePhase || 0) + delta * 9;
        if (mesh.userData.rightArm) {
          mesh.userData.rightArm.rotation.x = 0;
          mesh.userData.rightArm.rotation.z = -2.4 + Math.sin(record.emotePhase) * 0.45;
        }
      }
    }

    this.nearestPlayer = (minPeerDist <= 8.5) ? nearestPeer : null;
    const playerPromptEl = document.getElementById('playerInteractPrompt');
    const playerPromptName = document.getElementById('playerInteractPromptName');
    const mobilePlayerBtn = document.getElementById('btnMobilePlayerAction');
    if (playerPromptEl && playerPromptName) {
      if (this.nearestPlayer && this.nearestPlayer.data) {
        playerPromptName.textContent = this.nearestPlayer.data.fullName || this.nearestPlayer.data.username || 'Pemain';
        playerPromptEl.style.display = 'flex';
        if (mobilePlayerBtn) {
          mobilePlayerBtn.style.display = 'flex';
          mobilePlayerBtn.textContent = '👋 Sapa ' + (this.nearestPlayer.data.username || '');
        }
      } else {
        playerPromptEl.style.display = 'none';
        if (mobilePlayerBtn) mobilePlayerBtn.style.display = 'none';
      }
    }
  }

  interactNearestPlayer() {
    if (this.nearestPlayer && this.nearestPlayer.data && typeof window.openPlayerInteractModal === 'function') {
      this.playTone(720, 'sine', 0.1);
      window.openPlayerInteractModal(this.nearestPlayer.data);
      return true;
    }
    return false;
  }

  setupInputs() {
    window.addEventListener('keydown', e => {
      if (document.activeElement && (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA')) return;
      this.keys[e.key.toLowerCase()] = true;
      if (e.key === ' ' || e.code === 'Space') this.jump();
      if (e.key.toLowerCase() === 'e') this.interactNearestHotspot();
      if (e.key.toLowerCase() === 'f') this.interactNearestPlayer();
      if (e.key.toLowerCase() === 'c') this.cycleCamera();
      if (e.key.toLowerCase() === 'r') this.toggleRoofMode();
      // Tombol [M]: buka/tutup modal peta (minimap), sinkron dengan dokumen QA A.2.
      if (e.key.toLowerCase() === 'm' && typeof window.toggleMapModal === 'function') window.toggleMapModal();
    });

    window.addEventListener('keyup', e => {
      this.keys[e.key.toLowerCase()] = false;
    });

    // 1. Mouse Drag Camera Orbit Controls & Click Raycast
    let clickStartX = 0, clickStartY = 0, isRealDrag = false;
    this.canvas.addEventListener('mousedown', e => {
      this.isDragging = true;
      isRealDrag = false;
      clickStartX = e.clientX;
      clickStartY = e.clientY;
      this.prevPointer = { x: e.clientX, y: e.clientY };
    });

    window.addEventListener('mousemove', e => {
      if (!this.isDragging) return;
      const dx = e.clientX - this.prevPointer.x;
      const dy = e.clientY - this.prevPointer.y;
      if (Math.abs(e.clientX - clickStartX) > 4 || Math.abs(e.clientY - clickStartY) > 4) {
        isRealDrag = true;
      }
      this.prevPointer = { x: e.clientX, y: e.clientY };

      this.camYaw -= dx * 0.006;
      this.camPitch = Math.max(-1.2, Math.min(1.25, this.camPitch + dy * 0.005)); // POV: bisa melihat ke atas
    });

    window.addEventListener('mouseup', e => {
      this.isDragging = false;
      if (!isRealDrag && Math.hypot(e.clientX - clickStartX, e.clientY - clickStartY) < 6) {
        this.handleClickRaycast(e.clientX, e.clientY);
      }
    });

    // 2. Mouse Wheel Zoom In / Out (allows broader campus perspective)
    // Min distance diturunkan (8 -> 4) agar zoom dekat tetap nyaman untuk karakter chibi kecil.
    this.canvas.addEventListener('wheel', e => {
      e.preventDefault();
      this.camDistance = Math.max(4, Math.min(115, this.camDistance + e.deltaY * 0.025));
    }, { passive: false });

    // 2b. Zoom HP: cubit dua jari di layar (seperti Roblox) + tombol +/− (#zoomInBtn/#zoomOutBtn).
    let pinchDist = 0;
    const pinchSpan = (tl) => Math.hypot(tl[0].clientX - tl[1].clientX, tl[0].clientY - tl[1].clientY);
    this.canvas.addEventListener('touchstart', e => {
      if (e.targetTouches.length >= 2) pinchDist = pinchSpan(e.targetTouches);
    }, { passive: true });
    this.canvas.addEventListener('touchmove', e => {
      if (e.targetTouches.length < 2 || !pinchDist) return;
      const d = pinchSpan(e.targetTouches);
      this.camDistance = Math.max(4, Math.min(115, this.camDistance * (pinchDist / d)));
      pinchDist = d;
    }, { passive: true });
    this.canvas.addEventListener('touchend', e => { if (e.targetTouches.length < 2) pinchDist = 0; });
    const zoomBtn = (id, f) => {
      const b = document.getElementById(id);
      if (b) b.addEventListener('click', () => { this.camDistance = Math.max(4, Math.min(115, this.camDistance * f)); });
    };
    zoomBtn('zoomInBtn', 0.8);
    zoomBtn('zoomOutBtn', 1.25);

    // 3. Touch Drag Camera Controls (on right half of screen or non-joystick touches)
    let camTouchId = null;
    this.canvas.addEventListener('touchstart', e => {
      for (let i = 0; i < e.changedTouches.length; i++) {
        const t = e.changedTouches[i];
        if (t.clientX > window.innerWidth * 0.35) {
          camTouchId = t.identifier;
          this.prevPointer = { x: t.clientX, y: t.clientY };
          break;
        }
      }
    }, { passive: true });

    this.canvas.addEventListener('touchmove', e => {
      if (camTouchId === null || e.targetTouches.length >= 2) return;
      for (let i = 0; i < e.changedTouches.length; i++) {
        const t = e.changedTouches[i];
        if (t.identifier === camTouchId) {
          const dx = t.clientX - this.prevPointer.x;
          const dy = t.clientY - this.prevPointer.y;
          this.prevPointer = { x: t.clientX, y: t.clientY };

          this.camYaw -= dx * 0.007;
          this.camPitch = Math.max(-1.2, Math.min(1.25, this.camPitch + dy * 0.006));
          break;
        }
      }
    }, { passive: true });

    const resetCamTouch = (e) => {
      for (let i = 0; i < e.changedTouches.length; i++) {
        if (e.changedTouches[i].identifier === camTouchId) {
          camTouchId = null;
          break;
        }
      }
    };
    this.canvas.addEventListener('touchend', resetCamTouch);
    this.canvas.addEventListener('touchcancel', resetCamTouch);

    // 4. Touch Virtual Joystick for Movement
    const joyZone = document.getElementById('touchJoystickZone');
    const joyKnob = document.getElementById('touchJoystickKnob');
    if (joyZone && joyKnob) {
      let touchId = null;
      let center = { x: 0, y: 0 };

      joyZone.addEventListener('touchstart', e => {
        if (e.cancelable) e.preventDefault();
        const t = e.changedTouches[0];
        touchId = t.identifier;
        const rect = joyZone.getBoundingClientRect();
        center = { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 };
        this.handleJoystickMove(t.clientX - center.x, t.clientY - center.y, joyKnob);
      }, { passive: false });

      joyZone.addEventListener('touchmove', e => {
        if (e.cancelable) e.preventDefault();
        for (let i = 0; i < e.changedTouches.length; i++) {
          const t = e.changedTouches[i];
          if (t.identifier === touchId) {
            this.handleJoystickMove(t.clientX - center.x, t.clientY - center.y, joyKnob);
            break;
          }
        }
      }, { passive: false });

      const resetJoy = (e) => {
        if (e && e.changedTouches && touchId !== null) {
          let found = false;
          for (let i = 0; i < e.changedTouches.length; i++) {
            if (e.changedTouches[i].identifier === touchId) {
              found = true;
              break;
            }
          }
          if (!found) return; // not the joystick touch!
        }
        if (e && e.cancelable) e.preventDefault();
        touchId = null;
        this.joystickVector = { x: 0, y: 0 };
        joyKnob.style.transform = 'translate(-50%, -50%)';
      };
      joyZone.addEventListener('touchend', resetJoy, { passive: false });
      joyZone.addEventListener('touchcancel', resetJoy, { passive: false });
    }
  }

  handleJoystickMove(dx, dy, knob) {
    const dist = Math.sqrt(dx * dx + dy * dy);
    const maxR = 45;
    const clampedDist = Math.min(dist, maxR);
    const angle = Math.atan2(dy, dx);
    const kx = Math.cos(angle) * clampedDist;
    const ky = Math.sin(angle) * clampedDist;
    knob.style.transform = `translate(calc(-50% + ${kx}px), calc(-50% + ${ky}px))`;
    this.joystickVector = { x: kx / maxR, y: ky / maxR };
  }

  handleClickRaycast(screenX, screenY) {
    if (!this.camera || !this.scene || !this.canvas) return;
    const rect = this.canvas.getBoundingClientRect();
    const mouse = new THREE.Vector2(
      ((screenX - rect.left) / rect.width) * 2 - 1,
      -((screenY - rect.top) / rect.height) * 2 + 1
    );
    const raycaster = new THREE.Raycaster();
    raycaster.setFromCamera(mouse, this.camera);

    // 1. Raycast against remote player avatars
    if (this.remotePlayers && this.remotePlayers.size > 0) {
      const peerMeshes = [];
      for (const rec of this.remotePlayers.values()) {
        if (rec.mesh && rec.mesh.visible) {
          rec.mesh.traverse(child => {
            if (child.isMesh) {
              child.userData.parentRecord = rec;
              peerMeshes.push(child);
            }
          });
        }
      }
      const intersects = raycaster.intersectObjects(peerMeshes, false);
      if (intersects.length > 0) {
        const hit = intersects[0].object;
        if (hit.userData && hit.userData.parentRecord) {
          const rec = hit.userData.parentRecord;
          if (typeof window.openPlayerInteractModal === 'function') {
            this.playTone(720, 'sine', 0.1);
            window.openPlayerInteractModal(rec.data);
            return;
          }
        }
      }
    }

    // 2. Raycast against interactive hotspot beacons
    if (this.hotspotMeshes && this.hotspotMeshes.length > 0) {
      const beaconMeshes = this.hotspotMeshes.map(hm => hm.beacon).filter(Boolean);
      const intersects = raycaster.intersectObjects(beaconMeshes, false);
      if (intersects.length > 0) {
        const hit = intersects[0].object;
        const found = this.hotspotMeshes.find(hm => hm.beacon === hit);
        if (found && found.data && typeof found.data.action === 'function') {
          this.playTone(680, 'sine', 0.12);
          found.data.action();
        }
      }
    }
  }

  jump() {
    if (this.isSitting) { this.standUp(); return; }
    if (this.isGrounded) {
      this.playerVelocity.y = JUMP_VELOCITY;
      this.isGrounded = false;
      this.playTone(320, 'square', 0.1);
    }
  }

  /** Wave emote: right arm raises and swings for ~1.5s (HUD 😀 button). */
  emote() {
    this.emoteTime = 1.5;
    this.playTone(660, 'sine', 0.1);
    setTimeout(() => this.playTone(880, 'sine', 0.12), 140);
    return true;
  }

  toggleSprint(force) {
    this.isSprinting = (force !== undefined) ? force : !this.isSprinting;
  }

  cycleCamera() {
    if (this.cameraMode === 'third_person') this.cameraMode = 'first_person';
    else if (this.cameraMode === 'first_person') this.cameraMode = 'top_down';
    else this.cameraMode = 'third_person';
  }

  toggleRoofMode() {
    this.options.roofMode = !this.options.roofMode;
    this.updateBuildingInteriorState();
    return this.options.roofMode;
  }

  playTone(freq, type = 'sine', duration = 0.1) {
    try {
      if (!this.audioCtx) {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        this.audioCtx = new AudioContext();
      }
      const osc = this.audioCtx.createOscillator();
      const gain = this.audioCtx.createGain();
      osc.type = type;
      osc.frequency.setValueAtTime(freq, this.audioCtx.currentTime);
      gain.gain.setValueAtTime(0.15, this.audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, this.audioCtx.currentTime + duration);
      osc.connect(gain);
      gain.connect(this.audioCtx.destination);
      osc.start();
      osc.stop(this.audioCtx.currentTime + duration);
    } catch(e) {}
  }

  updatePlayer(delta) {
    let inputX = 0;
    let inputZ = 0;

    // Keyboard Input
    if (this.keys['w'] || this.keys['arrowup']) inputZ -= 1;
    if (this.keys['s'] || this.keys['arrowdown']) inputZ += 1;
    if (this.keys['a'] || this.keys['arrowleft']) inputX -= 1;
    if (this.keys['d'] || this.keys['arrowright']) inputX += 1;

    // Joystick Input
    if (Math.abs(this.joystickVector.x) > 0.1 || Math.abs(this.joystickVector.y) > 0.1) {
      inputX = this.joystickVector.x;
      inputZ = this.joystickVector.y;
    }

    const inputLen = Math.sqrt(inputX * inputX + inputZ * inputZ);
    this.isMoving = (inputLen > 0.05);

    // Sedang duduk: tahan pose; gerak/lompat = berdiri.
    if (this.isSitting) {
      if (this.isMoving || this.keys[' ']) {
        this.standUp();
      } else {
        if (this.leftLeg) this.leftLeg.rotation.x = -Math.PI / 2;
        if (this.rightLeg) this.rightLeg.rotation.x = -Math.PI / 2;
        if (this.leftArm) this.leftArm.rotation.x = -0.4;
        if (this.rightArm) this.rightArm.rotation.x = -0.4;
        this.player.position.copy(this.playerPos);
        this.player.rotation.y = 0;
        this.isMoving = false;
        return;
      }
    }

    const isSprintActive = (this.isSprinting || this.keys['shift']);
    const speed = (isSprintActive ? SPRINT_SPEED : WALK_SPEED) * (this.speedMul || 1) * delta; // unit per frame dari delta nyata (speedMul: botol minum ≤1.25 → tetap < batas WS 40)

    // Breathing & idle accumulator
    this.idleTime = (this.idleTime || 0) + delta;

    if (this.isMoving) {
      const nx = inputX / (inputLen || 1);
      const nz = inputZ / (inputLen || 1);

      // Camera-relative movement (Forward = -sinY, -cosY; Right = cosY, -sinY)
      const sinY = Math.sin(this.camYaw);
      const cosY = Math.cos(this.camYaw);

      const moveX = (nx * cosY + nz * sinY) * speed;
      const moveZ = (-nx * sinY + nz * cosY) * speed;

      const nextX = this.playerPos.x + moveX;
      const nextZ = this.playerPos.z + moveZ;

      // Check Collision against Colliders
      if (!this.checkCollision(nextX, this.playerPos.z)) {
        this.playerPos.x = nextX;
      }
      if (!this.checkCollision(this.playerPos.x, nextZ)) {
        this.playerPos.z = nextZ;
      }

      this.headingAngle = Math.atan2(moveX, moveZ);
      // Putar badan HALUS ke arah jalan (dulu langsung "patah" → terlihat kaku).
      this.player.rotation.y = vcLerpAngle(this.player.rotation.y, this.headingAngle, Math.min(1, delta * 14));

      // Forward lean posture for running vs walking
      const forwardLean = isSprintActive ? 0.16 : 0.06;
      if (this.playerTorso) this.playerTorso.rotation.x += (forwardLean - this.playerTorso.rotation.x) * Math.min(1, delta * 8);

      // Limb swing with stride frequency and amplitude scaled by speed
      const strideRate = isSprintActive ? 18 : 11;
      const strideAmp = isSprintActive ? 0.8 : 0.55;
      this.walkAnimTime += delta * strideRate;
      const swing = Math.sin(this.walkAnimTime) * strideAmp;

      if (this.leftArm) { this.leftArm.rotation.x = -swing; this.leftArm.rotation.z = -0.08; }
      if (this.rightArm && this.emoteTime <= 0) { this.rightArm.rotation.x = swing; this.rightArm.rotation.z = 0.08; }
      if (this.leftLeg) this.leftLeg.rotation.x = swing;
      if (this.rightLeg) this.rightLeg.rotation.x = -swing;
      // Badan naik-turun tiap langkah + sedikit berayun kiri-kanan (langkah terasa hidup).
      const bob = Math.abs(Math.sin(this.walkAnimTime)) * (isSprintActive ? 0.22 : 0.13);
      const ub = this.player.userData;
      if (this.playerTorso) { this.playerTorso.position.y = (ub.torsoY !== undefined ? ub.torsoY : 3.8) + bob; this.playerTorso.rotation.z = Math.sin(this.walkAnimTime) * 0.045; }
      if (this.playerHead) { this.playerHead.position.y = (ub.headY !== undefined ? ub.headY + bob * 0.1 : 6.2 + bob * 1.1); this.playerHead.rotation.y *= 0.85; this.playerHead.rotation.z = -Math.sin(this.walkAnimTime) * 0.03; }
      this.idleTime = 0;
    } else {
      // Idle: napas, tangan & kaki kembali PERLAHAN (bukan langsung 0), kepala sesekali menoleh.
      const breathe = Math.sin(this.idleTime * 2.2);
      const ease = Math.min(1, delta * 9);
      if (this.playerTorso) {
        this.playerTorso.rotation.x += (0 - this.playerTorso.rotation.x) * ease;
        this.playerTorso.rotation.z += (0 - this.playerTorso.rotation.z) * ease;
        this.playerTorso.position.y = (this.player.userData.torsoY !== undefined ? this.player.userData.torsoY : 3.8) + breathe * 0.05;
      }
      if (this.playerHead) {
        this.playerHead.position.y = (this.player.userData.headY !== undefined ? this.player.userData.headY + breathe * 0.03 : 6.2 + breathe * 0.07);
        const look = this.idleTime > 2.5 ? Math.sin(this.idleTime * 0.6) * 0.35 * Math.min(1, (this.idleTime - 2.5) / 2) : 0;
        this.playerHead.rotation.y += (look - this.playerHead.rotation.y) * ease * 0.5;
        this.playerHead.rotation.z += (0 - this.playerHead.rotation.z) * ease;
      }
      if (this.leftArm) {
        this.leftArm.rotation.x += (0 - this.leftArm.rotation.x) * ease;
        this.leftArm.rotation.z = -0.06 + breathe * 0.02;
      }
      if (this.rightArm && this.emoteTime <= 0) {
        this.rightArm.rotation.x += (0 - this.rightArm.rotation.x) * ease;
        this.rightArm.rotation.z = 0.06 - breathe * 0.02;
      }
      if (this.leftLeg) this.leftLeg.rotation.x += (0 - this.leftLeg.rotation.x) * ease;
      if (this.rightLeg) this.rightLeg.rotation.x += (0 - this.rightLeg.rotation.x) * ease;
    }

    // In-air Jump Pose (tuck legs, raise arms)
    if (!this.isGrounded) {
      if (this.leftLeg) this.leftLeg.rotation.x = 0.42;
      if (this.rightLeg) this.rightLeg.rotation.x = 0.28;
      if (this.leftArm) this.leftArm.rotation.z = -0.38;
      if (this.rightArm && this.emoteTime <= 0) this.rightArm.rotation.z = 0.38;
    }

    // Emote wave overrides the right arm while active
    if (this.emoteTime > 0) {
      this.emoteTime = Math.max(0, this.emoteTime - delta);
      this.emotePhase = (this.emotePhase || 0) + delta * 9;
      if (this.rightArm) {
        this.rightArm.rotation.x = 0;
        this.rightArm.rotation.z = -2.4 + Math.sin(this.emotePhase) * 0.45;
      }
    } else if (this.rightArm && this.isMoving && this.rightArm.rotation.z !== 0) {
      this.rightArm.rotation.z = 0;
    }

    // Pijakan sungguhan: tanah, lantai gedung, pelat lantai atas, tangga (tanpa "lantai virtual").
    const groundY = this.groundHeightAt(this.playerPos.x, this.playerPos.z, this.playerPos.y);
    if (this.isGrounded && groundY < this.playerPos.y - 0.05) {
      this.isGrounded = false;            // melangkah keluar tepi (mis. lubang tangga) → jatuh
      this.playerVelocity.y = 0;
    }
    if (!this.isGrounded) {
      this.playerVelocity.y -= GRAVITY * delta;
      this.playerPos.y += this.playerVelocity.y * delta;
      if (this.playerPos.y <= groundY) {
        this.playerPos.y = groundY;
        this.playerVelocity.y = 0;
        this.isGrounded = true;
      }
    } else {
      this.playerPos.y = groundY;          // naik/turun anak tangga mengikuti pijakan
    }
    this.currentFloor = this.levelAt(this.playerPos.y);

    this.player.position.copy(this.playerPos);

    // Main bola: sentuh = giring/tendang (jarak HORIZONTAL; dulu jarak 3D dari kaki → hampir tak pernah kena).
    if (this.soccerBall) {
      const ball = this.soccerBall.position;
      const dx = ball.x - this.playerPos.x, dz = ball.z - this.playerPos.z;
      const hd = Math.hypot(dx, dz);
      this.ballKickCd = Math.max(0, (this.ballKickCd || 0) - delta);
      if (hd < 2.4 && Math.abs(this.playerPos.y - 0) < 2) {
        const nx = hd > 0.001 ? dx / hd : Math.sin(this.headingAngle || 0), nz = hd > 0.001 ? dz / hd : Math.cos(this.headingAngle || 0);
        const power = (this.isSprinting || this.keys['shift']) ? 34 : 22;   // unit/detik
        this.ballVelocity.set(nx * power, 0, nz * power);
        ball.x = this.playerPos.x + nx * 2.45; ball.z = this.playerPos.z + nz * 2.45; // jangan tembus badan
        if (this.ballKickCd <= 0) { this.playTone(180, 'sine', 0.15); this.ballKickCd = 0.4; }
      }
      const sp = Math.hypot(this.ballVelocity.x, this.ballVelocity.z);
      if (sp > 0.05) {
        ball.x += this.ballVelocity.x * delta; ball.z += this.ballVelocity.z * delta;
        const fr = Math.exp(-1.6 * delta);                     // gesekan per detik (halus di semua FPS)
        this.ballVelocity.x *= fr; this.ballVelocity.z *= fr;
        const B = this.ballBounds, r = this.ballRadius || 0.9;
        if (B) {
          if (ball.x < B.minX + r) { ball.x = B.minX + r; this.ballVelocity.x = Math.abs(this.ballVelocity.x) * 0.7; }
          if (ball.x > B.maxX - r) { ball.x = B.maxX - r; this.ballVelocity.x = -Math.abs(this.ballVelocity.x) * 0.7; }
          if (ball.z < B.minZ + r) { ball.z = B.minZ + r; this.ballVelocity.z = Math.abs(this.ballVelocity.z) * 0.7; }
          if (ball.z > B.maxZ - r) { ball.z = B.maxZ - r; this.ballVelocity.z = -Math.abs(this.ballVelocity.z) * 0.7; }
        }
        // Menggelinding: putar sesuai jarak tempuh
        this.soccerBall.rotation.x += (this.ballVelocity.z * delta) / r;
        this.soccerBall.rotation.z -= (this.ballVelocity.x * delta) / r;
      } else {
        this.ballVelocity.set(0, 0, 0);
      }
    }
  }

  checkCollision(x, z) {
    // Pad ≈ setengah lebar badan avatar (skala 0.6); pintu 8 unit tetap lega dilewati.
    const pad = 1.1;
    for (const c of this.colliders) {
      if (x >= c.minX - pad && x <= c.maxX + pad &&
          z >= c.minZ - pad && z <= c.maxZ + pad) {
        // Tabrak hanya bila badan pemain (y..y+4.5) beririsan dengan rentang tinggi collider.
        const cMin = c.minY || 0, cMax = (c.maxY !== undefined) ? c.maxY : c.height;
        if (this.playerPos.y + 4.5 > cMin && this.playerPos.y + 0.4 < cMax) {
          return true;
        }
      }
    }
    return false;
  }

  updateBuildingInteriorState() {
    if (!this.campusData || !this.campusData.buildings) return;
    const px = this.playerPos.x;
    const pz = this.playerPos.z;

    let insideBuilding = null;
    for (const b of this.campusData.buildings) {
      const halfW = b.bounds.width / 2;
      const halfD = b.bounds.depth / 2;
      if (px >= b.bounds.x - halfW && px <= b.bounds.x + halfW &&
          pz >= b.bounds.z - halfD && pz <= b.bounds.z + halfD) {
        insideBuilding = b;
        break;
      }
    }

    this.insideBuilding = insideBuilding;

    // Gedung bertingkat: saat di dalam, sembunyikan lantai di atas pemain (dinding, pelat, perabot)
    // agar kamera bisa melihat ruangan; di luar semua lantai tampil.
    const myLevel = this.levelAt(this.playerPos.y) - 1;
    this.hiddenAbove = (insideBuilding && insideBuilding.levelGroups && insideBuilding.levelGroups.length > 1)
      ? { b: insideBuilding, y: (myLevel + 1) * FLOOR_H - 0.5 } : null;
    for (const b of this.campusData.buildings) {
      if (!b.levelGroups) continue;
      b.levelGroups.forEach(lg => { lg.visible = (b !== insideBuilding) || lg.userData.level <= myLevel; });
    }

    // Dynamic Roof Transparency: when player is inside a building, hide that building's roof
    // so camera and player have crystal-clear interior visibility and props view!
    for (const b of this.campusData.buildings) {
      if (b.roofGroup) {
        if (!this.options.roofMode) {
          b.roofGroup.visible = false;
        } else if (insideBuilding === b) {
          b.roofGroup.visible = false; // Player is inside this building!
        } else {
          b.roofGroup.visible = true; // Player is outside!
        }
      }
    }
  }

  updateCamera() {
    if (!this.player) return;

    // Perluas layar & FOV kamera ketika berada di dalam ruangan agar pandangan lapang & lega
    const isIndoors = !!this.insideBuilding;
    const defaultFov = (this.cameraMode === 'first_person') ? 70 : 54;
    const indoorFov = (this.cameraMode === 'first_person') ? 85 : 74;
    const targetFov = isIndoors ? indoorFov : defaultFov;
    if (Math.abs(this.camera.fov - targetFov) > 0.05) {
      this.camera.fov += (targetFov - this.camera.fov) * 0.1;
      this.camera.updateProjectionMatrix();
    }

    if (this.cameraMode === 'first_person') {
      // Tinggi mata disesuaikan karakter chibi (~1.5 unit tinggi → mata ~1.2 unit).
      const eyeY = PLAYER_EYE_Y;
      this.camera.position.set(this.playerPos.x, this.playerPos.y + eyeY, this.playerPos.z);
      const lookTarget = new THREE.Vector3(
        this.playerPos.x - Math.sin(this.camYaw) * 10,
        this.playerPos.y + eyeY - Math.sin(this.camPitch) * 10,
        this.playerPos.z - Math.cos(this.camYaw) * 10
      );
      this.camera.lookAt(lookTarget);
    } else if (this.cameraMode === 'top_down') {
      const topHeight = isIndoors ? 32 : 40;
      this.camera.position.set(this.playerPos.x, this.playerPos.y + topHeight, this.playerPos.z);
      this.camera.lookAt(this.playerPos);
    } else {
      // Third Person with Smooth Orbital Mouse Camera ("maos")
      // Saat di dalam ruangan (indoors), jarak orbital dioptimalkan dan sudut sedikit dinaikkan
      // agar tidak terpotong dinding dan seluruh ruangan terlihat lapang.
      const effCamDist = isIndoors ? Math.min(this.camDistance, 18) : this.camDistance;
      const effPitch = isIndoors ? Math.max(this.camPitch, 0.46) : this.camPitch;

      const hDist = effCamDist * Math.cos(effPitch);
      const vDist = effCamDist * Math.sin(effPitch) + PLAYER_LOOK_Y + 1.5;
      const targetCamX = this.playerPos.x + Math.sin(this.camYaw) * hDist;
      const targetCamZ = this.playerPos.z + Math.cos(this.camYaw) * hDist;
      const targetCamY = this.playerPos.y + vDist;

      let camX = targetCamX, camZ = targetCamZ;
      const ib = this.insideBuilding && this.insideBuilding.bounds;
      if (ib) {                                   // tetap di dalam ruangan (inset 1.6 dari dinding)
        camX = Math.max(ib.x - ib.width / 2 + 1.6, Math.min(ib.x + ib.width / 2 - 1.6, camX));
        camZ = Math.max(ib.z - ib.depth / 2 + 1.6, Math.min(ib.z + ib.depth / 2 - 1.6, camZ));
      }
      this.camera.position.lerp(new THREE.Vector3(camX, targetCamY, camZ), 0.18);
      this.camera.lookAt(new THREE.Vector3(this.playerPos.x, this.playerPos.y + PLAYER_LOOK_Y, this.playerPos.z));
    }
  }

  animate() {
    requestAnimationFrame(() => this.animate());
    // Dashboard lobi (sebelum masuk permainan) sedang terbuka: hentikan render 3D
    // sementara supaya HP/laptop tidak boros CPU-GPU dan UI tetap responsif.
    if (window.__vcLobbyOpen === true) return;
    // Delta nyata (dibatasi 50 ms) agar kecepatan sama di HP 30 fps maupun layar 120 Hz.
    const now = performance.now();
    const delta = Math.min(0.05, Math.max(0.001, ((now - (this.__lastFrameAt || now - 16)) / 1000)));
    this.__lastFrameAt = now;
    if (this.contextLost) return;          // GPU sedang dipulihkan
    this.watchPerformance(now);

    this.updatePlayer(delta);
    this.updateRemotePlayers(delta);
    this.updateCamera();
    this.updateSunFollow();
    this.updateHotspots(delta);
    this.updateNav(now);
    this.updateBuildingInteriorState();

    // Gentle fountain water ripple shimmer
    if (this.fountainWater) {
      this.fountainWater.rotation.y += 0.01;
    }

    if (this.renderer && this.scene && this.camera) {
      this.renderer.render(this.scene, this.camera);
      // Hook QA: jumlah draw call bingkai terakhir (Agent 1 verifikasi performa).
      window.__vcDrawCalls = this.renderer.info.render.calls;
      window.__vcTriangles = this.renderer.info.render.triangles;
    }
  }

  onWindowResize() {
    if (!this.camera || !this.renderer) return;
    this.camera.aspect = window.innerWidth / window.innerHeight;
    this.camera.updateProjectionMatrix();
    this.renderer.setSize(window.innerWidth, window.innerHeight);
  }

  /** (Kompatibilitas) Lantai kini fisik: naik/turun lewat tangga. */
  switchFloor() {}

  /**
   * Navigasi ke lokasi: kompas & teks tujuan menunjuk ke target, tiang cahaya penanda di lokasi,
   * jarak dalam meter, dan callback onArrive saat pemain tiba (≤ radius, lantai sama).
   * t = { x, z, floor, name, radius?, onArrive? }  — null untuk menghapus.
   */
  setNavTarget(t) {
    this.navTarget = t ? { x: Number(t.x) || 0, z: Number(t.z) || 0, floor: Number(t.floor) || 1, name: String(t.name || 'Tujuan'), radius: Number(t.radius) || 9, onArrive: t.onArrive || null } : null;
    if (!this.navBeacon && this.navTarget) {
      const g = new THREE.Group();
      const beam = new THREE.Mesh(new THREE.CylinderGeometry(1.6, 1.6, 60, 12, 1, true),
        new THREE.MeshBasicMaterial({ color: 0xffcf4a, transparent: true, opacity: 0.28, depthWrite: false, side: THREE.DoubleSide }));
      beam.position.y = 30;
      const ring = new THREE.Mesh(new THREE.RingGeometry(3.2, 4.2, 28), new THREE.MeshBasicMaterial({ color: 0xffcf4a, transparent: true, opacity: 0.8, side: THREE.DoubleSide, depthWrite: false }));
      ring.rotation.x = -Math.PI / 2; ring.position.y = 0.25;
      const arrow = new THREE.Mesh(new THREE.ConeGeometry(1.6, 3, 4), new THREE.MeshBasicMaterial({ color: 0xffb020 }));
      arrow.rotation.x = Math.PI; arrow.position.y = 9;
      g.add(beam); g.add(ring); g.add(arrow);
      g.userData.arrow = arrow;
      this.navBeacon = g;
      this.scene.add(g);
    }
    if (this.navBeacon) {
      this.navBeacon.visible = !!this.navTarget;
      if (this.navTarget) {
        const fy = this.navTarget.floor > 1 ? (this.navTarget.floor - 1) * FLOOR_H + SLAB_T / 2 : 0;
        this.navBeacon.position.set(this.navTarget.x, fy, this.navTarget.z);
      }
    }
    const txt = document.getElementById('objectiveText');
    if (txt && !this.navTarget && this.__navPrevText) { txt.textContent = this.__navPrevText; this.__navPrevText = null; }
  }

  updateNav(now) {
    const t = this.navTarget;
    if (!t) return;
    if (this.navBeacon && this.navBeacon.userData.arrow) {
      this.navBeacon.userData.arrow.position.y = 9 + Math.sin(now / 300) * 1.2;
      this.navBeacon.userData.arrow.rotation.y += 0.04;
    }
    if (now - (this.__navTick || 0) < 250) return;
    this.__navTick = now;
    const dist = Math.hypot(t.x - this.playerPos.x, t.z - this.playerPos.z);
    const txt = document.getElementById('objectiveText');
    if (txt) {
      if (this.__navPrevText == null) this.__navPrevText = txt.textContent;
      const fl = this.currentFloor || 1;
      txt.textContent = '🧭 ' + t.name + ' · ' + Math.round(dist) + ' m' + (t.floor !== fl ? ' · naik/turun ke Lantai ' + t.floor : '');
    }
    if (dist <= t.radius && (this.currentFloor || 1) === t.floor) {
      const cb = t.onArrive;
      const name = t.name;
      this.setNavTarget(null);
      if (window.UI && UI.toast) UI.toast('📍 Sampai di ' + name, 'green', 3000);
      if (typeof cb === 'function') { try { cb(); } catch (e) {} }
    }
  }

  teleportTo(x, z, floor = 1) {
    const y = (floor > 1) ? (floor - 1) * FLOOR_H + SLAB_T / 2 : 0;
    this.playerPos.set(x, y, z);
    this.playerPos.y = this.groundHeightAt(x, z, y);
    this.isGrounded = true;
    this.currentFloor = this.levelAt(this.playerPos.y);
    if (this.player) this.player.position.copy(this.playerPos);
  }
}

window.VirtualCampus3D = VirtualCampus3D;

/** Interpolasi sudut lewat jalur terpendek (hindari putaran 350° saat menoleh). */
function vcLerpAngle(a, b, t) {
  let d = ((b - a + Math.PI) % (Math.PI * 2));
  if (d < 0) d += Math.PI * 2;
  return a + (d - Math.PI) * t;
}

/* ================== INTEGRASI MODUL GAME (Workstream B/C/E) ==================
 * Helper global dunia 3D. Semuanya OPSIONAL & non-breaking: bila modul
 * character-story.js / missions-minigame.js / live-chat.js tidak termuat,
 * dunia 3D berjalan normal seperti sebelumnya.
 * LiveChat diinit dari halaman (WorldController::render3DPage) dengan objek WS
 * yang sudah ada — dunia 3D hanya memperbarui konteks charName/locationName.
 * Event WS 'server_deleted' juga ditangani di handler WS halaman 3D.
 * ========================================================================== */

function vc3dEsc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, (ch) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]
  ));
}

/** HUD objektif story arc aktif (kecil di atas) + bar progres misi server. */
/**
 * Buka mini-game untuk missionId hotspot 3D. Mapping missionId → tipe:
 * MISSION_MINIGAME_MAP (default 'wire').
 */
function vc3dOpenMissionMinigame(missionId) {
  if (!window.MiniGames) return false;
  const mid = String(missionId || '');
  if (!mid) return false;
  const type = MISSION_MINIGAME_MAP[mid] || 'wire';
  // Mini-game hanya menyelesaikan misi server (tidak ada langkah cerita karakter lagi).
  MiniGames.open(mid, { type: type }, () => {});
  return true;
}

