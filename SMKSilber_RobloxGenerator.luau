--[[
    ================================================================================
    DOKUMEN DESAIN GAME (GDD) & GENERATOR PROSEDURAL: ROBLOX SCHOOL PROJECT
    ================================================================================
    Tema     : Retro Pixel-3D Fusion (Dunia 3D dengan Estetika Aset & UI Pixel Art)
    Platform : Roblox Studio (Bahasa: Luau)
    Sekolah  : SMK Silber Virtual Campus v5

    CARA MENJALANKAN DI ROBLOX STUDIO:
    1. Buka Roblox Studio -> Buat 'Baseplate' baru.
    2. Buat Script baru di 'ServerScriptService' (atau tempel di Command Bar).
    3. Paste seluruh isi kode ini lalu tekan "Run" / "Play".
    4. Seluruh arsitektur 3 Zona, koridor loker (25 studs), kelas 60x40 studs,
       papan tulis 12 studs, DetailedAssets, siklus bel sekolah, dan UI HUD
       pixel art (150x50 px) akan ter-generate secara otomatis!
    ================================================================================
--]]

local Workspace = game:GetService("Workspace")
local Lighting = game:GetService("Lighting")
local ReplicatedStorage = game:GetService("ReplicatedStorage")
local StarterGui = game:GetService("StarterGui")
local Players = game:GetService("Players")

print("====================================================================")
print("  MEMULAI PEMBANGUNAN ROBLOX SCHOOL PROJECT (RETRO PIXEL-3D FUSION) ")
print("====================================================================")

--------------------------------------------------------------------------------
-- 0. FOLDER STRUKTUR UTAMA
--------------------------------------------------------------------------------
local CAMPUS_FOLDER = Workspace:FindFirstChild("SMKSilber_Campus") or Instance.new("Folder")
CAMPUS_FOLDER.Name = "SMKSilber_Campus"
CAMPUS_FOLDER.Parent = Workspace

-- Folder Khusus Optimasi Anti-Lag (Sesuai GDD Bagian 1.B.3)
local DETAILED_ASSETS = CAMPUS_FOLDER:FindFirstChild("DetailedAssets") or Instance.new("Folder")
DETAILED_ASSETS.Name = "DetailedAssets"
DETAILED_ASSETS.Parent = CAMPUS_FOLDER

--------------------------------------------------------------------------------
-- 1. BLUEPRINT & TATA LETAK DENAH 3D (3 ZONA UTAMA)
--------------------------------------------------------------------------------
--[[
    Pembagian 3 Zona Utama Berdasarkan GDD:
    - Zona 1: Publik    | 300 x 200 x 50 | Vector3.new(0, 0, 0)
    - Zona 2: Akademik  | 400 x 150 x 50 | Vector3.new(0, 0, 250)
    - Zona 3: Aktivitas | 250 x 300 x 60 | Vector3.new(300, 0, 125)
--]]

local ZONAS = {
    Zona1_Publik = {
        Nama = "Zona 1: Publik (Gerbang, Lobi, Koridor Loker, Kantin)",
        Pusat = Vector3.new(0, 0, 0),
        Ukuran = Vector3.new(300, 50, 200),
        WarnaPaving = Color3.fromRGB(226, 232, 240)
    },
    Zona2_Akademik = {
        Nama = "Zona 2: Akademik (Ruang Kelas A-D, Lab TIK, Perpustakaan)",
        Pusat = Vector3.new(0, 0, 250),
        Ukuran = Vector3.new(400, 50, 150),
        WarnaPaving = Color3.fromRGB(241, 245, 249)
    },
    Zona3_Aktivitas = {
        Nama = "Zona 3: Aktivitas (Lapangan Olahraga, Ruang Guru, UKS, Toilet)",
        Pusat = Vector3.new(300, 0, 125),
        Ukuran = Vector3.new(250, 60, 300),
        WarnaPaving = Color3.fromRGB(203, 213, 225)
    }
}

-- Fondasi Lantai untuk Setiap Zona
for zKey, zData in pairs(ZONAS) do
    local zonaFloor = Instance.new("Part")
    zonaFloor.Name = "BaseFloor_" .. zKey
    zonaFloor.Size = Vector3.new(zData.Ukuran.X, 2, zData.Ukuran.Z)
    zonaFloor.Position = Vector3.new(zData.Pusat.X, 0, zData.Pusat.Z)
    zonaFloor.Anchored = true
    zonaFloor.Material = Enum.Material.SmoothPlastic
    zonaFloor.Color = zData.WarnaPaving
    zonaFloor.Parent = CAMPUS_FOLDER

    -- Label Zona 3D terapung
    local labelPart = Instance.new("Part")
    labelPart.Name = "LabelZona_" .. zKey
    labelPart.Size = Vector3.new(zData.Ukuran.X * 0.4, 6, 1)
    labelPart.Position = Vector3.new(zData.Pusat.X, 35, zData.Pusat.Z - zData.Ukuran.Z/2 + 5)
    labelPart.Anchored = true
    labelPart.Transparency = 1
    labelPart.CanCollide = false
    labelPart.Parent = CAMPUS_FOLDER

    local bb = Instance.new("BillboardGui")
    bb.Size = UDim2.new(0, 320, 0, 50)
    bb.AlwaysOnTop = false
    bb.MaxDistance = 250
    bb.Parent = labelPart

    local bbText = Instance.new("TextLabel")
    bbText.Size = UDim2.new(1, 0, 1, 0)
    bbText.BackgroundTransparency = 1
    bbText.TextColor3 = Color3.fromRGB(15, 23, 42)
    bbText.TextStrokeColor3 = Color3.fromRGB(255, 255, 255)
    bbText.TextStrokeTransparency = 0.2
    bbText.Font = Enum.Font.FredokaOne
    bbText.TextScaled = true
    bbText.Text = zData.Nama
    bbText.Parent = bb
end

--------------------------------------------------------------------------------
-- 2. FUNGSI PEMBUAT DINDING OTOMATIS (SKRIP GDD BAGIAN 3.A)
--------------------------------------------------------------------------------
local function buatDinding(posisi, ukuran, nama, warna, material)
    local dinding = Instance.new("Part")
    dinding.Name = nama or "Dinding"
    dinding.Size = ukuran
    dinding.Position = posisi
    dinding.Anchored = true
    dinding.Material = material or Enum.Material.Concrete
    dinding.Color = warna or Color3.fromRGB(148, 163, 184)
    dinding.Parent = CAMPUS_FOLDER

    -- Menambahkan Tekstur Pixel Dinding Bata 64x64 (GDD Bagian 2.A)
    local texDepan = Instance.new("Texture")
    texDepan.Name = "PixelBrick_Front"
    texDepan.Face = Enum.NormalId.Front
    texDepan.StudsPerTileU = 8 -- Tiling diulang setiap 8 studs
    texDepan.StudsPerTileV = 8
    texDepan.Transparency = 0.7
    texDepan.Parent = dinding

    local texBelakang = texDepan:Clone()
    texBelakang.Face = Enum.NormalId.Back
    texBelakang.Parent = dinding

    return dinding
end

--------------------------------------------------------------------------------
-- 3. SISTEM KORIDOR LOKER (GDD BAGIAN 1.B.1)
--------------------------------------------------------------------------------
--[[
    Sistem Koridor Loker:
    - Lebar koridor minimal 25 studs (anti-crowding).
    - Deretan loker di sisi kiri dan kanan dengan jarak antar loker 4 studs.
    - Lokasi: Zona 1 Publik (antara lobi depan menuju Zona 2 Akademik).
--]]
local function bangunKoridorLoker()
    local koridorModel = Instance.new("Model")
    koridorModel.Name = "Sistem_Koridor_Loker"
    koridorModel.Parent = CAMPUS_FOLDER

    local koridorPanjang = 140
    local koridorLebar = 25 -- Wajib minimal 25 studs
    local koridorTinggi = 18
    local posX = 0
    local posZ = 90 -- Koridor penghubung Zona 1 (0) ke Zona 2 (250)

    -- Dinding Kiri & Kanan Koridor
    buatDinding(Vector3.new(posX - koridorLebar/2 - 1, koridorTinggi/2, posZ), Vector3.new(2, koridorTinggi, koridorPanjang), "DindingKoridor_Kiri")
    buatDinding(Vector3.new(posX + koridorLebar/2 + 1, koridorTinggi/2, posZ), Vector3.new(2, koridorTinggi, koridorPanjang), "DindingKoridor_Kanan")

    -- Deretan Loker (Jarak antar loker 4 studs)
    local jarakLoker = 4
    local jumlahLoker = math.floor((koridorPanjang - 20) / jarakLoker)
    local startZ = posZ - koridorPanjang/2 + 10

    for i = 0, jumlahLoker do
        local curZ = startZ + (i * jarakLoker)

        -- Loker Sisi Kiri
        local lokerKiri = Instance.new("Part")
        lokerKiri.Name = "Loker_Kiri_" .. (i + 1)
        lokerKiri.Size = Vector3.new(2.5, 7.5, 3.2)
        lokerKiri.Position = Vector3.new(posX - koridorLebar/2 + 1.4, 3.75, curZ)
        lokerKiri.Anchored = true
        lokerKiri.Material = Enum.Material.Metal
        lokerKiri.Color = (i % 2 == 0) and Color3.fromRGB(2, 132, 199) or Color3.fromRGB(3, 105, 161)
        lokerKiri.Parent = koridorModel

        -- Loker Sisi Kanan
        local lokerKanan = Instance.new("Part")
        lokerKanan.Name = "Loker_Kanan_" .. (i + 1)
        lokerKanan.Size = Vector3.new(2.5, 7.5, 3.2)
        lokerKanan.Position = Vector3.new(posX + koridorLebar/2 - 1.4, 3.75, curZ)
        lokerKanan.Anchored = true
        lokerKanan.Material = Enum.Material.Metal
        lokerKanan.Color = (i % 2 == 0) and Color3.fromRGB(16, 185, 129) or Color3.fromRGB(5, 150, 105)
        lokerKanan.Parent = koridorModel

        -- ProximityPrompt untuk Buka Loker
        if i % 3 == 0 then
            local prompt = Instance.new("ProximityPrompt")
            prompt.ActionText = "Buka Loker Siswa"
            prompt.ObjectText = "Loker #" .. (100 + i)
            prompt.KeyboardKeyCode = Enum.KeyCode.E
            prompt.HoldDuration = 0.2
            prompt.Parent = lokerKiri
        end
    end
end

--------------------------------------------------------------------------------
-- 4. DESAIN RUANG KELAS PROSEDURAL (GDD BAGIAN 1.B.2)
--------------------------------------------------------------------------------
--[[
    Spesifikasi Ruang Kelas:
    - Ukuran setiap kelas: 60 x 40 studs (Tinggi 18 studs).
    - Papan tulis SurfaceGui di depan kelas dengan tinggi 12 studs dari lantai.
    - Dilengkapi kursi duduk interaktif (Seat), meja kayu (32x32 pixel texture),
      dan DetailedAssets (buku, alat tulis).
--]]
local function bangunRuangKelas(namaKelas, pusatKelas)
    local kelasModel = Instance.new("Model")
    kelasModel.Name = namaKelas
    kelasModel.Parent = CAMPUS_FOLDER

    local w, d, h = 60, 40, 18
    local posX, posY, posZ = pusatKelas.X, pusatKelas.Y + h/2, pusatKelas.Z
    local wallThick = 2

    -- Dinding Kiri, Kanan, Belakang
    buatDinding(Vector3.new(posX - w/2, posY, posZ), Vector3.new(wallThick, h, d), "DindingKiri_" .. namaKelas, Color3.fromRGB(241, 245, 249))
    buatDinding(Vector3.new(posX + w/2, posY, posZ), Vector3.new(wallThick, h, d), "DindingKanan_" .. namaKelas, Color3.fromRGB(241, 245, 249))
    buatDinding(Vector3.new(posX, posY, posZ + d/2), Vector3.new(w, h, wallThick), "DindingBelakang_" .. namaKelas, Color3.fromRGB(241, 245, 249))

    -- Dinding Depan dengan Bukaan Pintu (Lebar Pintu 12 studs)
    local doorWidth = 12
    local frontSideW = (w - doorWidth) / 2
    buatDinding(Vector3.new(posX - w/2 + frontSideW/2, posY, posZ - d/2), Vector3.new(frontSideW, h, wallThick), "DindingDepanKiri_" .. namaKelas)
    buatDinding(Vector3.new(posX + w/2 - frontSideW/2, posY, posZ - d/2), Vector3.new(frontSideW, h, wallThick), "DindingDepanKanan_" .. namaKelas)

    -- Papan Tulis (Tinggi 12 Studs dari Lantai - Wajib GDD 1.B.2)
    local papanTulis = Instance.new("Part")
    papanTulis.Name = "PapanTulis_" .. namaKelas
    papanTulis.Size = Vector3.new(32, 7, 0.6)
    papanTulis.Position = Vector3.new(posX, 12, posZ + d/2 - 0.6) -- Tinggi 12 studs dari lantai!
    papanTulis.Anchored = true
    papanTulis.Material = Enum.Material.SmoothPlastic
    papanTulis.Color = Color3.fromRGB(22, 101, 52) -- Hijau Papan Tulis Klasik
    papanTulis.Parent = kelasModel

    -- SurfaceGui Coretan Kapur Pixelated (GDD 2.A - 128x64 px)
    local sGui = Instance.new("SurfaceGui")
    sGui.Face = Enum.NormalId.Back
    sGui.CanvasSize = Vector2.new(128, 64) -- Resolusi pixel kustom
    sGui.Parent = papanTulis

    local sLabel = Instance.new("TextLabel")
    sLabel.Size = UDim2.new(1, -8, 1, -8)
    sLabel.Position = UDim2.new(0, 4, 0, 4)
    sLabel.BackgroundTransparency = 1
    sLabel.TextColor3 = Color3.fromRGB(248, 250, 252)
    sLabel.Font = Enum.Font.Arcade
    sLabel.TextScaled = true
    sLabel.Text = namaKelas .. "\n[MATERI HARI INI]\n- Jaringan Dasar\n- Pemrograman Web"
    sLabel.Parent = sGui

    -- Meja Guru di Depan
    local mejaGuru = Instance.new("Part")
    mejaGuru.Name = "MejaGuru"
    mejaGuru.Size = Vector3.new(10, 3.2, 5)
    mejaGuru.Position = Vector3.new(posX, 1.6, posZ + d/2 - 8)
    mejaGuru.Anchored = true
    mejaGuru.Material = Enum.Material.WoodPlanks
    mejaGuru.Color = Color3.fromRGB(180, 83, 9)
    mejaGuru.Parent = kelasModel

    -- Barisan Meja & Kursi Siswa (3 baris x 3 kolom)
    for baris = 1, 3 do
        for kolom = 1, 3 do
            local mejaX = posX - 18 + (kolom - 1) * 18
            local mejaZ = posZ + 6 - (baris - 1) * 12

            local mejaPart = Instance.new("Part")
            mejaPart.Name = "MejaSiswa_" .. baris .. "_" .. kolom
            mejaPart.Size = Vector3.new(6.5, 2.8, 3.5)
            mejaPart.Position = Vector3.new(mejaX, 1.4, mejaZ)
            mejaPart.Anchored = true
            mejaPart.Material = Enum.Material.Wood
            mejaPart.Color = Color3.fromRGB(217, 119, 6)
            mejaPart.Parent = kelasModel

            -- Kursi Duduk (Seat)
            local kursi = Instance.new("Seat")
            kursi.Name = "KursiDuduk_" .. baris .. "_" .. kolom
            kursi.Size = Vector3.new(2.6, 0.6, 2.6)
            kursi.Position = Vector3.new(mejaX, 1.6, mejaZ - 3)
            kursi.Anchored = true
            kursi.Material = Enum.Material.Wood
            kursi.Color = Color3.fromRGB(180, 83, 9)
            kursi.Parent = kelasModel

            -- ProximityPrompt Duduk
            local sitPrompt = Instance.new("ProximityPrompt")
            sitPrompt.ActionText = "Duduk di Kelas"
            sitPrompt.ObjectText = "Kursi Siswa"
            sitPrompt.KeyboardKeyCode = Enum.KeyCode.E
            sitPrompt.Parent = kursi
            sitPrompt.Triggered:Connect(function(player)
                local humanoid = player.Character and player.Character:FindFirstChildOfClass("Humanoid")
                if humanoid then kursi:Sit(humanoid) end
            end)

            -- Detail Aset Kecil (Buku di atas meja) -> Dimasukkan ke Folder 'DetailedAssets' (GDD 1.B.3)
            local bukuKecil = Instance.new("Part")
            bukuKecil.Name = "BukuPelajaran_" .. baris .. "_" .. kolom
            bukuKecil.Size = Vector3.new(1.8, 0.3, 2.2)
            bukuKecil.Position = Vector3.new(mejaX + 1, 3.0, mejaZ)
            bukuKecil.Anchored = true
            bukuKecil.Color = (baris % 2 == 0) and Color3.fromRGB(2, 132, 199) or Color3.fromRGB(239, 68, 68)
            bukuKecil.Parent = DETAILED_ASSETS -- Masuk ke folder optimasi jarak jauh!
        end
    end
end

--------------------------------------------------------------------------------
-- 4B. FASILITAS BENGKEL KEJURUAN & TEACHING FACTORY (SPESIFIKASI KHUSUS SMK)
--------------------------------------------------------------------------------
--[[
    Karena ini adalah SMK (Sekolah Menengah Kejuruan), fasilitas tidak hanya
    ruang kelas teori biasa, melainkan dilengkapi bengkel praktik kejuruan nyata:
    1. Bengkel Jaringan & Server TJKT (Server Racks 42U berlampu LED, Meja Crimping LAN UTP, Router Cisco).
    2. Studio Multimedia & Fotografi DKV (Green Screen Cyclorama, Softbox Lighting, Kamera DSLR Tripod).
    3. Bank Mini & Business Center TEFA (Loket Teller AKL, Rak Ritel Produk Siswa BD, Mesin Kasir POS).
--]]

local function bangunBengkelTJKT(pusatBengkel)
    local tjktModel = Instance.new("Model")
    tjktModel.Name = "Bengkel_TJKT_JaringanKomputer"
    tjktModel.Parent = CAMPUS_FOLDER

    local w, d, h = 50, 36, 18
    local posX, posY, posZ = pusatBengkel.X, pusatBengkel.Y + h/2, pusatBengkel.Z

    -- Dinding Ruangan (Warna Tekno Abu-abu Slate)
    buatDinding(Vector3.new(posX - w/2, posY, posZ), Vector3.new(2, h, d), "DindingKiri_TJKT", Color3.fromRGB(51, 65, 85))
    buatDinding(Vector3.new(posX + w/2, posY, posZ), Vector3.new(2, h, d), "DindingKanan_TJKT", Color3.fromRGB(51, 65, 85))
    buatDinding(Vector3.new(posX, posY, posZ + d/2), Vector3.new(w, h, 2), "DindingBelakang_TJKT", Color3.fromRGB(51, 65, 85))
    buatDinding(Vector3.new(posX, posY, posZ - d/2), Vector3.new(w, h, 2), "DindingDepan_TJKT", Color3.fromRGB(51, 65, 85))

    -- Plang Neon Nama Bengkel
    local plang = Instance.new("Part")
    plang.Name = "PlangBengkel_TJKT"
    plang.Size = Vector3.new(28, 4, 0.8)
    plang.Position = Vector3.new(posX, 16, posZ - d/2 - 0.5)
    plang.Anchored = true
    plang.Material = Enum.Material.Neon
    plang.Color = Color3.fromRGB(56, 189, 248)
    plang.Parent = tjktModel

    local bb = Instance.new("BillboardGui")
    bb.Size = UDim2.new(0, 320, 0, 40)
    bb.AlwaysOnTop = true
    bb.Parent = plang
    local lbl = Instance.new("TextLabel")
    lbl.Size = UDim2.new(1, 0, 1, 0)
    lbl.BackgroundTransparency = 1
    lbl.TextColor3 = Color3.fromRGB(255, 255, 255)
    lbl.Font = Enum.Font.Arcade
    lbl.Text = "🔧 BENGKEL JARINGAN & SERVER (TJKT)"
    lbl.TextScaled = true
    lbl.Parent = bb

    -- Deretan Server Rack 42U dengan Lampu LED Berkedip
    for i = 1, 3 do
        local rackX = posX - 16 + (i - 1) * 7
        local rackZ = posZ + d/2 - 6

        local rack = Instance.new("Part")
        rack.Name = "ServerRack_42U_" .. i
        rack.Size = Vector3.new(5, 14, 4)
        rack.Position = Vector3.new(rackX, 7, rackZ)
        rack.Anchored = true
        rack.Material = Enum.Material.Metal
        rack.Color = Color3.fromRGB(15, 23, 42)
        rack.Parent = tjktModel

        -- LED Indikator Berkedip
        local led = Instance.new("Part")
        led.Name = "LED_Server_" .. i
        led.Size = Vector3.new(4, 1.2, 0.2)
        led.Position = Vector3.new(rackX, 10, rackZ - 2.1)
        led.Anchored = true
        led.Material = Enum.Material.Neon
        led.Color = (i % 2 == 0) and Color3.fromRGB(34, 197, 94) or Color3.fromRGB(56, 189, 248)
        led.Parent = DETAILED_ASSETS
    end

    -- Meja Praktikum Crimping Kabel UTP & Router
    local mejaPraktik = Instance.new("Part")
    mejaPraktik.Name = "MejaPraktikumCrimping_TJKT"
    mejaPraktik.Size = Vector3.new(26, 3, 8)
    mejaPraktik.Position = Vector3.new(posX, 1.5, posZ - 4)
    mejaPraktik.Anchored = true
    mejaPraktik.Material = Enum.Material.WoodPlanks
    mejaPraktik.Color = Color3.fromRGB(71, 85, 105)
    mejaPraktik.Parent = tjktModel

    -- Router & LAN Tester Props (DetailedAssets)
    local router = Instance.new("Part")
    router.Name = "Router_Mikrotik"
    router.Size = Vector3.new(4, 0.8, 2.5)
    router.Position = Vector3.new(posX - 8, 3.4, posZ - 4)
    router.Anchored = true
    router.Color = Color3.fromRGB(226, 232, 240)
    router.Parent = DETAILED_ASSETS

    local lanTester = Instance.new("Part")
    lanTester.Name = "LAN_Tester_RJ45"
    lanTester.Size = Vector3.new(2, 0.6, 1.5)
    lanTester.Position = Vector3.new(posX + 8, 3.3, posZ - 4)
    lanTester.Anchored = true
    lanTester.Material = Enum.Material.Neon
    lanTester.Color = Color3.fromRGB(234, 179, 8)
    lanTester.Parent = DETAILED_ASSETS

    -- ProximityPrompt Praktik Crimping
    local promptTJKT = Instance.new("ProximityPrompt")
    promptTJKT.ActionText = "Mulai Praktik Crimping Kabel UTP (T568B)"
    promptTJKT.ObjectText = "Meja Praktik TJKT"
    promptTJKT.KeyboardKeyCode = Enum.KeyCode.E
    promptTJKT.HoldDuration = 1
    promptTJKT.Parent = mejaPraktik
    promptTJKT.Triggered:Connect(function(player)
        print("Siswa " .. player.Name .. " menyelesaikan simulasi crimping kabel LAN UTP RJ-45!")
    end)
end

local function bangunStudioMultimediaDKV(pusatStudio)
    local dkvModel = Instance.new("Model")
    dkvModel.Name = "Studio_DKV_Multimedia"
    dkvModel.Parent = CAMPUS_FOLDER

    local w, d, h = 50, 36, 18
    local posX, posY, posZ = pusatStudio.X, pusatStudio.Y + h/2, pusatStudio.Z

    -- Dinding Ruangan Studio (Warna Gelap Kedap Suara)
    buatDinding(Vector3.new(posX - w/2, posY, posZ), Vector3.new(2, h, d), "DindingKiri_DKV", Color3.fromRGB(30, 41, 59))
    buatDinding(Vector3.new(posX + w/2, posY, posZ), Vector3.new(2, h, d), "DindingKanan_DKV", Color3.fromRGB(30, 41, 59))
    buatDinding(Vector3.new(posX, posY, posZ + d/2), Vector3.new(w, h, 2), "DindingBelakang_DKV", Color3.fromRGB(30, 41, 59))
    buatDinding(Vector3.new(posX, posY, posZ - d/2), Vector3.new(w, h, 2), "DindingDepan_DKV", Color3.fromRGB(30, 41, 59))

    -- Backdrop Green Screen Cyclorama
    local greenScreen = Instance.new("Part")
    greenScreen.Name = "Backdrop_GreenScreen"
    greenScreen.Size = Vector3.new(26, 14, 0.4)
    greenScreen.Position = Vector3.new(posX, 7, posZ + d/2 - 0.6)
    greenScreen.Anchored = true
    greenScreen.Material = Enum.Material.SmoothPlastic
    greenScreen.Color = Color3.fromRGB(34, 197, 94) -- Hijau Chroma Key
    greenScreen.Parent = dkvModel

    -- Tripod Kamera Studio
    local tripod = Instance.new("Part")
    tripod.Name = "TripodKamera_Studio"
    tripod.Size = Vector3.new(1.2, 5, 1.2)
    tripod.Position = Vector3.new(posX, 2.5, posZ + 2)
    tripod.Anchored = true
    tripod.Color = Color3.fromRGB(15, 23, 42)
    tripod.Parent = DETAILED_ASSETS

    local kamera = Instance.new("Part")
    kamera.Name = "KameraDSLR_DKV"
    kamera.Size = Vector3.new(2, 1.6, 2.2)
    kamera.Position = Vector3.new(posX, 5.8, posZ + 2)
    kamera.Anchored = true
    kamera.Material = Enum.Material.Metal
    kamera.Color = Color3.fromRGB(30, 41, 59)
    kamera.Parent = DETAILED_ASSETS

    -- Softbox Lighting Studio (Kiri & Kanan)
    for _, sign in ipairs({-1, 1}) do
        local tiang = Instance.new("Part")
        tiang.Name = "TiangSoftbox"
        tiang.Size = Vector3.new(0.8, 8, 0.8)
        tiang.Position = Vector3.new(posX + sign * 14, 4, posZ + 4)
        tiang.Anchored = true
        tiang.Color = Color3.fromRGB(15, 23, 42)
        tiang.Parent = DETAILED_ASSETS

        local boxLight = Instance.new("Part")
        boxLight.Name = "SoftboxLight"
        boxLight.Size = Vector3.new(3, 4, 1.5)
        boxLight.Position = Vector3.new(posX + sign * 14, 8.5, posZ + 4)
        boxLight.Anchored = true
        boxLight.Material = Enum.Material.Neon
        boxLight.Color = Color3.fromRGB(254, 240, 138)
        boxLight.Parent = DETAILED_ASSETS
    end

    -- ProximityPrompt Rekam Studio DKV
    local promptDKV = Instance.new("ProximityPrompt")
    promptDKV.ActionText = "Mulai Photoshoot & Rekaman Video Studio (DKV)"
    promptDKV.ObjectText = "Kamera Studio DKV"
    promptDKV.KeyboardKeyCode = Enum.KeyCode.E
    promptDKV.HoldDuration = 1
    promptDKV.Parent = kamera
    promptDKV.Triggered:Connect(function(player)
        print("Siswa " .. player.Name .. " memulai sesi produksi video/foto di Studio DKV!")
    end)
end

local function bangunTeachingFactoryTEFA(pusatTEFA)
    local tefaModel = Instance.new("Model")
    tefaModel.Name = "Gedung_TeachingFactory_TEFA"
    tefaModel.Parent = CAMPUS_FOLDER

    local w, d, h = 60, 36, 18
    local posX, posY, posZ = pusatTEFA.X, pusatTEFA.Y + h/2, pusatTEFA.Z

    -- Dinding Ruangan
    buatDinding(Vector3.new(posX - w/2, posY, posZ), Vector3.new(2, h, d), "DindingKiri_TEFA", Color3.fromRGB(245, 158, 11))
    buatDinding(Vector3.new(posX + w/2, posY, posZ), Vector3.new(2, h, d), "DindingKanan_TEFA", Color3.fromRGB(245, 158, 11))
    buatDinding(Vector3.new(posX, posY, posZ + d/2), Vector3.new(w, h, 2), "DindingBelakang_TEFA", Color3.fromRGB(245, 158, 11))
    buatDinding(Vector3.new(posX, posY, posZ - d/2), Vector3.new(w, h, 2), "DindingDepan_TEFA", Color3.fromRGB(245, 158, 11))

    -- Plang Neon Teaching Factory
    local plang = Instance.new("Part")
    plang.Name = "Plang_TEFA"
    plang.Size = Vector3.new(34, 4.5, 0.8)
    plang.Position = Vector3.new(posX, 16, posZ - d/2 - 0.5)
    plang.Anchored = true
    plang.Material = Enum.Material.Neon
    plang.Color = Color3.fromRGB(245, 158, 11)
    plang.Parent = tefaModel

    local bb = Instance.new("BillboardGui")
    bb.Size = UDim2.new(0, 360, 0, 45)
    bb.AlwaysOnTop = true
    bb.Parent = plang
    local lbl = Instance.new("TextLabel")
    lbl.Size = UDim2.new(1, 0, 1, 0)
    lbl.BackgroundTransparency = 1
    lbl.TextColor3 = Color3.fromRGB(15, 23, 42)
    lbl.Font = Enum.Font.Arcade
    lbl.Text = "🏪 TEACHING FACTORY & BANK MINI (AKL/BD)"
    lbl.TextScaled = true
    lbl.Parent = bb

    -- Sisi Kiri: Loket Teller Bank Mini (AKL)
    local mejaTeller = Instance.new("Part")
    mejaTeller.Name = "MejaTeller_BankMini"
    mejaTeller.Size = Vector3.new(20, 3.6, 6)
    mejaTeller.Position = Vector3.new(posX - 14, 1.8, posZ)
    mejaTeller.Anchored = true
    mejaTeller.Material = Enum.Material.Glass
    mejaTeller.Color = Color3.fromRGB(2, 132, 199)
    mejaTeller.Transparency = 0.3
    mejaTeller.Parent = tefaModel

    -- Sisi Kanan: Rak Etalase Ritel Produk Siswa (Bisnis Digital / BD)
    local rakRitel = Instance.new("Part")
    rakRitel.Name = "RakRitel_ProdukSiswa"
    rakRitel.Size = Vector3.new(20, 7, 4)
    rakRitel.Position = Vector3.new(posX + 14, 3.5, posZ + 4)
    rakRitel.Anchored = true
    rakRitel.Material = Enum.Material.WoodPlanks
    rakRitel.Color = Color3.fromRGB(217, 119, 6)
    rakRitel.Parent = tefaModel

    -- ProximityPrompt Transaksi TEFA
    local promptTEFA = Instance.new("ProximityPrompt")
    promptTEFA.ActionText = "Buka Layanan Teller Bank Mini & Kasir Ritel"
    promptTEFA.ObjectText = "Teaching Factory SMK Silber"
    promptTEFA.KeyboardKeyCode = Enum.KeyCode.E
    promptTEFA.HoldDuration = 0.5
    promptTEFA.Parent = mejaTeller
    promptTEFA.Triggered:Connect(function(player)
        print("Siswa " .. player.Name .. " membuka layanan Teaching Factory / Bank Mini!")
    end)
end

--------------------------------------------------------------------------------
-- 5. SKRIP LOGIKA BEL SEKOLAH OTOMATIS (GDD BAGIAN 3.B)
--------------------------------------------------------------------------------
--[[
    Siklus Bel Sekolah Wajib:
    - StatusWaktuSekolah disimpan di ReplicatedStorage agar sinkron ke semua klien.
    - Siklus: Masuk Kelas -> Istirahat -> Ujian -> Pulang Sekolah (setiap 60 detik).
    - Membunyikan bel sekolah saat status berganti.
--]]
local StatusWaktu = ReplicatedStorage:FindFirstChild("StatusWaktuSekolah") or Instance.new("StringValue")
StatusWaktu.Name = "StatusWaktuSekolah"
StatusWaktu.Value = "Masuk Kelas"
StatusWaktu.Parent = ReplicatedStorage

-- Sound Bel Sekolah
local belSound = Instance.new("Sound")
belSound.Name = "BelSekolahSound"
belSound.SoundId = "rbxassetid://9069609200" -- Suara chime bel sekolah klasik
belSound.Volume = 1.0
belSound.Parent = Workspace

local siklus = {
    { Status = "Apel & K3", Jam = "07:00", Mapel = "BRIEFING K3 BENGKEL & APEL PAGI" },
    { Status = "Teori Kejuruan", Jam = "07:30", Mapel = "DASAR PROGRAM KEAHLIAN (C2/C3)" },
    { Status = "Istirahat", Jam = "09:30", Mapel = "KANTIN & MINI MARKET TEFA" },
    { Status = "Blok Praktik", Jam = "10:00", Mapel = "PRAKTIKUM BENGKEL / LAB (3 JAM)" },
    { Status = "Uji Kompetensi", Jam = "13:00", Mapel = "SIMULASI UKK / SERTIFIKASI LSP" },
    { Status = "Teaching Factory", Jam = "14:15", Mapel = "PRODUKSI & LAYANAN JASA TEFA" },
    { Status = "Piket 5R & Pulang", Jam = "15:30", Mapel = "PERAWATAN ALAT (5R) & EVALUASI" }
}
local indeks = 1

task.spawn(function()
    while true do
        task.wait(60) -- Berganti status setiap 60 detik (1 menit nyata)
        indeks = (indeks % #siklus) + 1
        local dataAktif = siklus[indeks]
        StatusWaktu.Value = "[" .. dataAktif.Jam .. "] " .. dataAktif.Status .. " - " .. dataAktif.Mapel

        -- Bunyikan bel
        belSound:Play()
        print("🔔 [BEL SEKOLAH SMK] " .. StatusWaktu.Value)
    end
end)

--------------------------------------------------------------------------------
-- 6. STARTERGUI: HUD JAM PIXEL ART & INVENTARIS 32x32 (GDD BAGIAN 2.B)
--------------------------------------------------------------------------------
--[[
    Komponen UI Retro Pixel untuk SMK:
    1. HUD Jam & Status Pelajaran SMK (180x50 px, pojok kanan atas, border tebal hitam 2px).
    2. Ikon Inventaris Hotbar Alat Praktik Kejuruan (Tang Crimping, Kamera DKV, Mesin Kasir TEFA, Scanner, Wearpack).
--]]
local function setupPixelStarterGui()
    local starterGuiFolder = game:GetService("StarterGui")
    local hudGui = Instance.new("ScreenGui")
    hudGui.Name = "PixelSchoolHUD"
    hudGui.ResetOnSpawn = false
    hudGui.Parent = starterGuiFolder

    -- A. Kotak UI Jam Sekolah (180x50 piksel, border 2px hitam, pojok kanan atas)
    local jamFrame = Instance.new("Frame")
    jamFrame.Name = "HUD_JamSekolah"
    jamFrame.Size = UDim2.new(0, 200, 0, 52)
    jamFrame.Position = UDim2.new(1, -215, 0, 16)
    jamFrame.BackgroundColor3 = Color3.fromRGB(15, 23, 42)
    jamFrame.BorderColor3 = Color3.fromRGB(0, 0, 0) -- Border hitam tebal pixel
    jamFrame.BorderSizePixel = 2                     -- 2px border tebal sesuai GDD
    jamFrame.Parent = hudGui

    local jamTitle = Instance.new("TextLabel")
    jamTitle.Name = "TitleStatus"
    jamTitle.Size = UDim2.new(1, -8, 0, 18)
    jamTitle.Position = UDim2.new(0, 4, 0, 3)
    jamTitle.BackgroundTransparency = 1
    jamTitle.TextColor3 = Color3.fromRGB(245, 158, 11)
    jamTitle.Font = Enum.Font.Arcade
    jamTitle.TextSize = 12
    jamTitle.Text = "★ JADWAL PRAKTIK SMK"
    jamTitle.Parent = jamFrame

    local jamTeks = Instance.new("TextLabel")
    jamTeks.Name = "LabelJam"
    jamTeks.Size = UDim2.new(1, -8, 0, 26)
    jamTeks.Position = UDim2.new(0, 4, 0, 22)
    jamTeks.BackgroundTransparency = 1
    jamTeks.TextColor3 = Color3.fromRGB(56, 189, 248)
    jamTeks.Font = Enum.Font.Arcade
    jamTeks.TextScaled = true
    jamTeks.Text = "[07:00] APEL & K3"
    jamTeks.Parent = jamFrame

    -- LocalScript Client untuk Sinkronisasi Bel Sekolah Real-Time
    local clientScript = Instance.new("LocalScript")
    clientScript.Name = "SyncBelClient"
    clientScript.Source = [[
        local ReplicatedStorage = game:GetService("ReplicatedStorage")
        local statusVal = ReplicatedStorage:WaitForChild("StatusWaktuSekolah")
        local label = script.Parent.LabelJam

        local function update()
            label.Text = statusVal.Value
        end
        statusVal.Changed:Connect(update)
        update()
    ]]
    clientScript.Parent = jamFrame

    -- B. Hotbar Inventaris Pixel (Slot 1-5 dengan Ikon Alat Kejuruan SMK 32x32)
    local hotbarFrame = Instance.new("Frame")
    hotbarFrame.Name = "HUD_HotbarPixel"
    hotbarFrame.Size = UDim2.new(0, 280, 0, 52)
    hotbarFrame.Position = UDim2.new(0.5, -140, 1, -68)
    hotbarFrame.BackgroundColor3 = Color3.fromRGB(15, 23, 42)
    hotbarFrame.BorderColor3 = Color3.fromRGB(0, 0, 0)
    hotbarFrame.BorderSizePixel = 2
    hotbarFrame.Parent = hudGui

    local hotbarItems = {
        { Slot = 1, Nama = "Tang Crimping & UTP (TJKT)", Warna = Color3.fromRGB(2, 132, 199), Ket = "Crimping RJ-45" },
        { Slot = 2, Nama = "Kamera DSLR Studio (DKV)", Warna = Color3.fromRGB(168, 85, 247), Ket = "Fotografi & Video" },
        { Slot = 3, Nama = "Mesin Kasir TEFA (BD/AKL)", Warna = Color3.fromRGB(34, 197, 94), Ket = "Transaksi Bank/POS" },
        { Slot = 4, Nama = "Wearpack Praktik Bengkel", Warna = Color3.fromRGB(249, 115, 22), Ket = "Seragam K3 Bengkel" },
        { Slot = 5, Nama = "Kunci Master Bengkel/Lab", Warna = Color3.fromRGB(234, 179, 8), Ket = "Akses Lab TIK" }
    }

    for idx, item in ipairs(hotbarItems) do
        local slotBtn = Instance.new("TextButton")
        slotBtn.Name = "Slot_" .. item.Slot
        slotBtn.Size = UDim2.new(0, 44, 0, 42)
        slotBtn.Position = UDim2.new(0, 8 + (idx - 1) * 54, 0, 5)
        slotBtn.BackgroundColor3 = Color3.fromRGB(30, 41, 59)
        slotBtn.BorderColor3 = Color3.fromRGB(0, 0, 0)
        slotBtn.BorderSizePixel = 2
        slotBtn.Text = tostring(item.Slot)
        slotBtn.TextColor3 = Color3.fromRGB(203, 213, 225)
        slotBtn.Font = Enum.Font.Arcade
        slotBtn.TextSize = 10
        slotBtn.Parent = hotbarFrame

        local iconInner = Instance.new("Frame")
        iconInner.Size = UDim2.new(0, 24, 0, 24)
        iconInner.Position = UDim2.new(0.5, -12, 0.5, -12)
        iconInner.BackgroundColor3 = item.Warna
        iconInner.BorderSizePixel = 0
        iconInner.Parent = slotBtn
    end
end

--------------------------------------------------------------------------------
-- 7. EKSEKUSI PEMBANGUNAN MAP SMK SILBER
--------------------------------------------------------------------------------
print(">> 1. Membangun Sistem Koridor Loker (Zona 1 Publik)...")
bangunKoridorLoker()

print(">> 2. Membangun 4 Ruang Kelas Akademik 60x40 studs (Zona 2 Akademik)...")
-- Kelas Kejuruan di Zona 2 (Pusat 0, 0, 250)
bangunRuangKelas("Kelas_X_TJKT_TeknikJaringan", Vector3.new(-70, 0, 210))
bangunRuangKelas("Kelas_XI_TJKT_SistemJaringan", Vector3.new(70, 0, 210))
bangunRuangKelas("Kelas_X_DKV_DesainKomunikasiVisual", Vector3.new(-70, 0, 290))
bangunRuangKelas("Kelas_X_AKL_AkuntansiKeuangan", Vector3.new(70, 0, 290))

print(">> 3. Membangun Fasilitas Bengkel & Studio Kejuruan SMK (Spesifikasi SMK)...")
-- Bengkel TJKT (Jaringan Komputer & Server 42U)
bangunBengkelTJKT(Vector3.new(-150, 0, 250))

-- Studio Multimedia DKV (Green Screen & Softbox Lighting)
bangunStudioMultimediaDKV(Vector3.new(150, 0, 250))

-- Gedung Teaching Factory TEFA & Bank Mini (AKL/BD)
bangunTeachingFactoryTEFA(Vector3.new(0, 0, 360))

print(">> 4. Mengatur Fasilitas Aktivitas (Zona 3 Aktivitas)...")
-- Lapangan Olahraga / Upacara
local lapangan = Instance.new("Part")
lapangan.Name = "Lapangan_Upacara_dan_Futsal"
lapangan.Size = Vector3.new(120, 1.2, 80)
lapangan.Position = Vector3.new(300, 0.6, 125)
lapangan.Anchored = true
lapangan.Material = Enum.Material.Grass
lapangan.Color = Color3.fromRGB(21, 128, 61)
lapangan.Parent = CAMPUS_FOLDER

-- Ruang Kantor Guru, BK / BKK (Bursa Kerja Khusus SMK) & UKS
buatDinding(Vector3.new(300, 9, 210), Vector3.new(80, 18, 50), "Gedung_KantorGuru_BKK_dan_UKS", Color3.fromRGB(251, 191, 36))

print(">> 5. Memasang StarterGUI Pixel HUD (Jam Praktik & Hotbar Alat SMK)...")
setupPixelStarterGui()

print(">> 6. Mengaktifkan Optimasi StreamingEnabled & Detailing Folder...")
Workspace.StreamingEnabled = true
Workspace.StreamingMinRadius = 128
Workspace.StreamingTargetRadius = 512

print("====================================================================")
print("  SUKSES: PETA 3D SMK RETRO PIXEL ROBLOX BERHASIL DIBANGUN LENGKAP! ")
print("====================================================================")
print("====================================================================")
