-- ===============================================================
--  PHP Excel Dışa/İçe Aktarma  |  Veritabanı Kurulum Dosyası
--  cilginyazilim.com
-- ---------------------------------------------------------------
--  KURULUM (iki yoldan biri):
--    1) Terminal :  mysql -u root -p < cy_excel.sql
--    2) phpMyAdmin > İçe Aktar > Dosya seç > cy_excel.sql > Başlat
--
--  NOT: Bu dosya veritabanını da oluşturur, ayrıca elle
--       "cy_excel" veritabanı açmanıza gerek yoktur.
-- ===============================================================

-- AUTO_INCREMENT sütuna 0 yazılırsa otomatik değer üretilmesin.
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
-- Tarihler Türkiye saat dilimine göre yorumlansın.
SET time_zone = "+03:00";
-- Bağlantı karakter setini utf8mb4 yap (Türkçe karakter + emoji desteği).
SET NAMES utf8mb4;

-- ---------------------------------------------------------------
--  1) Veritabanı
-- ---------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `cy_excel`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `cy_excel`;

-- ---------------------------------------------------------------
--  2) users tablosu
-- ---------------------------------------------------------------
--  Dosyayı tekrar çalıştırdığınızda hata almamak için önce siler.
--  DİKKAT: Bu satır mevcut verilerinizi siler!
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `name`    VARCHAR(150) NOT NULL,
  `surname` VARCHAR(150) NOT NULL,

  -- E-POSTA = İÇE AKTARMANIN ANAHTARIDIR.
  -- UNIQUE indeks sayesinde aynı e-posta iki kez kaydedilemez.
  -- İçe aktarmada "varsa güncelle, yoksa ekle" davranışı bu
  -- benzersizlik kuralına dayanır (bkz. system/ajax.php).
  `email`   VARCHAR(190) NOT NULL,

  `departman` VARCHAR(100) NOT NULL DEFAULT '',

  -- DECIMAL: Para için DOĞRU tip. FLOAT/DOUBLE kullanmayın;
  -- ikili kayan noktalı sayı 0.1 + 0.2 = 0.30000000000000004 gibi
  -- yuvarlama hataları üretir ve muhasebe kayıtlarını bozar.
  -- (10,2) = en fazla 10 basamak, bunun 2'si ondalık → 99.999.999,99
  `maas`    DECIMAL(10,2) DEFAULT NULL,

  -- DATE : Sadece gün (saat yok). Excel'den gelen tarihler
  -- buraya 'YYYY-MM-DD' biçiminde yazılır.
  `baslama_tarihi` DATE DEFAULT NULL,

  -- Sadece dosya ADI tutulur (örn. "a1b2c3.png"), tam yol değil.
  `image`   VARCHAR(191) NOT NULL DEFAULT '',

  -- Kayıt oluşturulduğunda o anın tarih/saati otomatik yazılır.
  `tarih`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- UNIQUE : Aynı e-postadan ikinci kayıt eklenemez.
  -- 190 karakter sınırı, utf8mb4 + InnoDB indeks bayt limiti
  -- (767 bayt) yüzündendir: 190 x 4 = 760 bayt.
  UNIQUE KEY `uniq_users_email` (`email`),

  -- İNDEKSLER: Arama ve sıralama yapılan sütunlara indeks eklemek,
  -- tablo büyüdükçe sorguları kat kat hızlandırır.
  KEY `idx_users_name` (`name`),
  KEY `idx_users_surname` (`surname`),
  KEY `idx_users_departman` (`departman`),
  KEY `idx_users_tarih` (`tarih`)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
--  3) Örnek veriler (50 kayıt)
-- ---------------------------------------------------------------
--  GÖRSEL ALANI NEDEN HEPSİNDE BOŞ?
--  Çünkü depoda örnek görsel BULUNMUYOR. Örnek veride dosya adı
--  yazsaydık (eskiden öyleydi), projeyi yeni indiren herkeste
--  veritabanı "bu kaydın fotoğrafı var" derken upload/ klasörü boş
--  olurdu; sonuç: 34 kayıtta kırık resim simgesi. Diskte olmayan bir
--  dosyaya veritabanından işaret etmek, en sık yapılan kurulum
--  tutarsızlığıdır.
--
--  Boş bırakınca uygulama "baş harf rozeti"ni gösterir (bkz.
--  system/ajax.php içindeki handle_list()); yani özellik yine
--  görünür. Bir kayda görsel yükler yüklemez fotoğraflı hâli de
--  görürsünüz.
--
--  Not: Maaşı ve tarihi bilerek boş bırakılmış kayıtlar vardır;
--  böylece Excel çıktısındaki BOŞ HÜCRE davranışını görebilirsiniz.
INSERT INTO `users`
    (`id`, `name`, `surname`, `email`, `departman`, `maas`, `baslama_tarihi`, `image`, `tarih`)
VALUES
( 1, 'Evren',   'ÇILGIN',   'evren.cilgin@ornek.com',   'Yazılım',    92500.00, '2019-03-04', '', '2025-01-06 19:34:27'),
( 2, 'Taha',    'BAYAR',    'taha.bayar@ornek.com',     'Yazılım',    78400.50, '2020-07-13', '', '2025-01-07 08:42:28'),
( 3, 'Zeynep',  'TURAN',    'zeynep.turan@ornek.com',   'İnsan Kaynakları', 64200.00, '2021-01-18', '', '2025-01-08 10:59:56'),
( 4, 'Mustafa', 'YILMAZ',   'mustafa.yilmaz@ornek.com', 'Satış',      58900.00, '2018-11-26', '', '2025-01-08 18:44:32'),
( 5, 'Elif',    'KAYA',     'elif.kaya@ornek.com',      'Muhasebe',   61750.75, '2022-02-14', '', '2025-01-09 17:47:28'),
( 6, 'Ahmet',   'DEMİR',    'ahmet.demir@ornek.com',    'Yazılım',    NULL,     '2023-05-02', '', '2025-01-11 11:49:05'),
( 7, 'Ayşe',    'ŞAHİN',    'ayse.sahin@ornek.com',     'Pazarlama',  55300.00, '2021-09-06', '', '2025-01-12 21:17:02'),
( 8, 'Mehmet',  'ÇELİK',    'mehmet.celik@ornek.com',   'Satış',      52100.00, '2020-04-20', '', '2025-01-14 04:24:09'),
( 9, 'Fatma',   'YILDIZ',   'fatma.yildiz@ornek.com',   'Muhasebe',   67800.00, '2017-08-01', '', '2025-01-15 23:58:29'),
(10, 'Emre',    'YILDIRIM', 'emre.yildirim@ornek.com',  'Yazılım',    88900.00, '2019-12-09', '', '2025-01-16 16:30:24'),
(11, 'Selin',   'ÖZTÜRK',   'selin.ozturk@ornek.com',   'Tasarım',    59400.00, '2022-06-27', '', '2025-01-17 17:06:42'),
(12, 'Burak',   'AYDIN',    'burak.aydin@ornek.com',    'Yazılım',    71200.00, '2021-03-15', '', '2025-01-17 18:08:06'),
(13, 'Merve',   'ÖZDEMİR',  'merve.ozdemir@ornek.com',  'Pazarlama',  NULL,     NULL, '', '2025-01-19 01:30:32'),
(14, 'Onur',    'ARSLAN',   'onur.arslan@ornek.com',    'Satış',      49800.00, '2023-01-30', '', '2025-01-19 20:00:51'),
(15, 'Ceren',   'DOĞAN',    'ceren.dogan@ornek.com',    'İnsan Kaynakları', 57600.00, '2020-10-12', '', '2025-01-20 14:09:31'),
(16, 'Kaan',    'KILIÇ',    'kaan.kilic@ornek.com',     'Yazılım',    96300.00, '2016-05-23', '', '2025-01-20 22:05:15'),
(17, 'Büşra',   'ASLAN',    'busra.aslan@ornek.com',    'Tasarım',    62500.00, '2022-09-05', '', '2025-01-21 08:36:59'),
(18, 'Serkan',  'ÇETİN',    'serkan.cetin@ornek.com',   'Muhasebe',   58100.00, '2019-07-08', '', '2025-01-22 16:06:12'),
(19, 'Gizem',   'KARA',     'gizem.kara@ornek.com',     'Pazarlama',  60900.00, '2021-11-22', '', '2025-01-24 10:30:31'),
(20, 'Barış',   'KOÇ',      'baris.koc@ornek.com',      'Yazılım',    83700.00, '2018-02-19', '', '2025-01-25 07:19:52'),
(21, 'Deniz',   'KURT',     'deniz.kurt@ornek.com',     'Satış',      NULL,     '2023-08-14', '', '2025-01-26 01:28:52'),
(22, 'Hakan',   'ÖZKAN',    'hakan.ozkan@ornek.com',    'Yazılım',    79500.00, '2020-01-07', '', '2025-01-27 19:52:10'),
(23, 'İrem',    'ŞİMŞEK',   'irem.simsek@ornek.com',    'İnsan Kaynakları', 63400.00, '2022-04-11', '', '2025-01-29 12:43:32'),
(24, 'Yusuf',   'POLAT',    'yusuf.polat@ornek.com',    'Muhasebe',   56800.00, '2021-06-03', '', '2025-01-29 20:10:46'),
(25, 'Melis',   'ÖZER',     'melis.ozer@ornek.com',     'Tasarım',    64100.00, '2020-12-28', '', '2025-01-30 22:06:37'),
(26, 'Cem',     'KORKMAZ',  'cem.korkmaz@ornek.com',    'Yazılım',    87200.00, '2017-10-16', '', '2025-01-31 03:44:01'),
(27, 'Esra',    'ÇAKIR',    'esra.cakir@ornek.com',     'Pazarlama',  54700.00, '2023-03-27', '', '2025-01-31 18:25:27'),
(28, 'Volkan',  'ERDOĞAN',  'volkan.erdogan@ornek.com', 'Satış',      51300.00, '2019-05-09', '', '2025-02-01 08:14:52'),
(29, 'Şeyma',   'GÜNEŞ',    'seyma.gunes@ornek.com',    'Muhasebe',   59900.00, '2021-08-24', '', '2025-02-01 14:27:09'),
(30, 'Uğur',    'AKSOY',    'ugur.aksoy@ornek.com',     'Yazılım',    74600.00, '2022-11-02', '', '2025-02-03 03:12:55'),
(31, 'Pınar',   'BULUT',    'pinar.bulut@ornek.com',    'Tasarım',    60300.00, '2020-03-18', '', '2025-02-04 20:02:24'),
(32, 'Tolga',   'TAŞ',      'tolga.tas@ornek.com',      'Satış',      48200.00, '2023-06-12', '', '2025-02-04 21:02:35'),
(33, 'Nazlı',   'KAPLAN',   'nazli.kaplan@ornek.com',   'İnsan Kaynakları', NULL, '2021-02-08', '', '2025-02-06 16:07:07'),
(34, 'Görkem',  'SOYLU',    'gorkem.soylu@ornek.com',   'Yazılım',    91800.00, '2016-09-21', '', '2025-02-08 01:23:35'),
(35, 'Damla',   'ATEŞ',     'damla.ates@ornek.com',     'Pazarlama',  57100.00, '2022-07-19', '', '2025-02-09 07:56:33'),
(36, 'Berk',    'GÜLER',    'berk.guler@ornek.com',     'Muhasebe',   62900.00, '2019-01-25', '', '2025-02-10 02:16:27'),
(37, 'Sude',    'BOZKURT',  'sude.bozkurt@ornek.com',   'Tasarım',    58600.00, '2023-04-06', '', '2025-02-10 18:54:39'),
(38, 'Alper',   'TEKİN',    'alper.tekin@ornek.com',    'Yazılım',    80400.00, '2018-08-30', '', '2025-02-11 10:55:00'),
(39, 'Ebru',    'ACAR',     'ebru.acar@ornek.com',      'Satış',      50500.00, '2021-12-13', '', '2025-02-13 09:17:40'),
(40, 'Sinan',   'BARAN',    'sinan.baran@ornek.com',    'Yazılım',    76900.00, '2020-05-04', '', '2025-02-15 08:26:15'),
(41, 'Aslı',    'SEZER',    'asli.sezer@ornek.com',     'İnsan Kaynakları', 61200.00, '2022-01-17', '', '2025-02-16 06:25:42'),
(42, 'Furkan',  'KOCA',     'furkan.koca@ornek.com',    'Pazarlama',  53800.00, '2023-09-25', '', '2025-02-17 21:37:35'),
(43, 'Nesrin',  'UZUN',     'nesrin.uzun@ornek.com',    'Muhasebe',   65400.00, '2018-06-11', '', '2025-02-18 17:36:38'),
(44, 'Okan',    'AVCI',     'okan.avci@ornek.com',      'Yazılım',    84100.00, '2017-04-28', '', '2025-02-19 06:17:27'),
(45, 'Tuğçe',   'KESKİN',   'tugce.keskin@ornek.com',   'Tasarım',    NULL,     '2023-11-07', '', '2025-02-20 05:21:28'),
(46, 'Murat',   'ÜNAL',     'murat.unal@ornek.com',     'Satış',      52700.00, '2020-08-15', '', '2025-02-21 08:10:22'),
(47, 'Yasemin', 'GÜL',      'yasemin.gul@ornek.com',    'İnsan Kaynakları', 59100.00, '2021-05-20', '', '2025-02-22 02:55:23'),
(48, 'Halil',   'DURMAZ',   'halil.durmaz@ornek.com',   'Muhasebe',   57900.00, '2019-09-03', '', '2025-02-22 18:23:50'),
(49, 'Beyza',   'SARI',     'beyza.sari@ornek.com',     'Pazarlama',  56200.00, '2022-03-29', '', '2025-02-23 10:36:41'),
(50, 'Ozan',    'TOPAL',    'ozan.topal@ornek.com',     'Yazılım',    73300.00, '2021-10-10', '', '2025-02-23 23:28:04');

-- ---------------------------------------------------------------
--  4) ESKİ CRUD ÖRNEĞİNDEN YÜKSELTME
-- ---------------------------------------------------------------
--  Elinizde eski "crud" veritabanındaki users tablosu varsa ve
--  verilerinizi KAYBETMEK İSTEMİYORSANIZ, yukarıdaki DROP/CREATE
--  yerine aşağıdaki komutları çalıştırın:
--
--    ALTER TABLE `users`
--      ADD `email` VARCHAR(190) NOT NULL AFTER `surname`,
--      ADD `departman` VARCHAR(100) NOT NULL DEFAULT '' AFTER `email`,
--      ADD `maas` DECIMAL(10,2) DEFAULT NULL AFTER `departman`,
--      ADD `baslama_tarihi` DATE DEFAULT NULL AFTER `maas`;
--
--  DİKKAT: UNIQUE indeksi eklemeden ÖNCE boş kalan e-posta
--  alanlarını doldurun; aksi halde ikinci boş satırda hata alırsınız:
--
--    UPDATE `users` SET `email` = CONCAT('kullanici', `id`, '@ornek.com')
--     WHERE `email` = '';
--    ALTER TABLE `users` ADD UNIQUE KEY `uniq_users_email` (`email`);
-- ===============================================================
