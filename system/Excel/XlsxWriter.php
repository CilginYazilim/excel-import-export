<?php
/**
 * =====================================================================
 *  XLSX YAZICI – Bağımlılıksız Excel (.xlsx) Üretici
 *  cilginyazilim.com – PHP Excel Dışa/İçe Aktarma
 * ---------------------------------------------------------------------
 *  .XLSX ASLINDA NEDİR?
 *  Bir .xlsx dosyası, sihirli bir ikili (binary) format DEĞİLDİR.
 *  Uzantısını .zip yapıp açarsanız içinde şu XML dosyalarını görürsünüz:
 *
 *    [Content_Types].xml          → "Paketteki her parça ne türdür?"
 *    _rels/.rels                  → "Paketin ana parçası hangisidir?"
 *    xl/workbook.xml              → Çalışma kitabı: sayfa listesi
 *    xl/_rels/workbook.xml.rels   → "sheet1 hangi dosyada duruyor?"
 *    xl/styles.xml                → Yazı tipi, dolgu, sayı biçimleri
 *    xl/worksheets/sheet1.xml     → ASIL VERİ (satırlar ve hücreler)
 *
 *  Bu sınıf tam olarak bu altı dosyayı üretip ZipArchive ile
 *  paketler. Bu yüzden Composer'a, PhpSpreadsheet'e veya başka
 *  hiçbir kütüphaneye ihtiyaç duymaz. Gereken tek şey PHP'nin
 *  yerleşik "zip" eklentisidir (XAMPP'ta hazır gelir).
 *
 *  NE YAPMAZ?
 *  Formül, grafik, birden fazla sayfa, hücre birleştirme gibi ileri
 *  özellikleri desteklemez. Amacı VERİ ALIŞVERİŞİDİR. Bunlara
 *  ihtiyacınız olursa PhpSpreadsheet'e geçin.
 *
 *  KULLANIMI:
 *      $writer = new XlsxWriter('Kullanıcılar');
 *      $writer->setHeader(['#', 'Ad'], [8, 20]);
 *      $writer->addRow([1, 'Evren'], ['int', 'text']);
 *      $writer->download('kullanicilar.xlsx');
 * =====================================================================
 */

declare(strict_types=1);

final class XlsxWriter
{
    /* -----------------------------------------------------------------
     *  STİL NUMARALARI (styles.xml içindeki cellXfs sırası)
     * -----------------------------------------------------------------
     *  Excel'de her hücre, "s" özniteliğiyle bir stile işaret eder.
     *  Örn. <c r="A1" s="1"> → 1 numaralı stil (başlık) kullanılsın.
     *  Bu sabitler, aşağıdaki styles.xml içinde tanımlı sıraya birebir
     *  karşılık gelir. Sırayı değiştirirseniz burayı da güncelleyin.
     * -------------------------------------------------------------- */
    private const STYLE_DEFAULT = 0;
    private const STYLE_HEADER  = 1;
    private const STYLE_TEXT    = 2;
    private const STYLE_INT     = 3;
    private const STYLE_MONEY   = 4;
    private const STYLE_DATE    = 5;

    /**
     * KORUMALI METİN STİLİ (quotePrefix).
     *
     * STYLE_TEXT ile GÖRÜNÜŞ OLARAK AYNIDIR; tek farkı Excel'e
     * "bu hücrenin içeriği kesinlikle METİNDİR" demesidir.
     * Neden gerektiğini aşağıdaki needsFormulaGuard() açıklıyor.
     */
    private const STYLE_TEXT_GUARDED = 6;

    /**
     * EXCEL TARİH BAŞLANGICI.
     *
     * Excel tarihleri metin olarak değil, SAYI olarak saklar:
     * "1900 tarih sistemi"nde 1 = 1 Ocak 1900'dür. Ancak Excel,
     * Lotus 1-2-3 ile uyum için 1900'ü hatalı biçimde artık yıl
     * sayar. Bu ünlü hatayı telafi etmenin en temiz yolu, başlangıcı
     * 30 Aralık 1899 kabul edip aradaki GÜN FARKINI yazmaktır.
     * Böylece 1 Mart 1900'den sonraki tüm tarihler doğru çıkar.
     */
    private const EXCEL_EPOCH = '1899-12-30';

    /** Sayfa (sekme) adı. */
    private string $sheetName;

    /** Üretilmiş satır XML'leri. Her eleman bir <row> etiketidir. */
    private array $rowsXml = [];

    /** Sütun genişlikleri (karakter cinsinden). */
    private array $columnWidths = [];

    /** Yazılmış satır sayısı (donmuş başlık ve filtre aralığı için). */
    private int $rowCount = 0;

    /** Sütun sayısı (autoFilter aralığını hesaplamak için). */
    private int $columnCount = 0;

    /**
     * @param string $sheetName Excel'de sekmede görünecek ad.
     */
    public function __construct(string $sheetName = 'Sayfa1')
    {
        // ZipArchive olmadan .xlsx üretilemez. Hatayı en başta ve
        // anlaşılır biçimde vermek, "boş dosya indi" gibi kafa
        // karıştırıcı sonuçlardan iyidir.
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(
                'PHP "zip" eklentisi etkin değil. php.ini içinde extension=zip satırını açın.'
            );
        }

        $this->sheetName = $this->sanitizeSheetName($sheetName);
    }

    /**
     * Başlık satırını yazar ve sütun genişliklerini ayarlar.
     *
     * @param string[] $labels Başlık metinleri
     * @param int[]    $widths Sütun genişlikleri (karakter sayısı)
     */
    public function setHeader(array $labels, array $widths = []): void
    {
        $this->columnWidths = $widths;
        $this->columnCount  = max($this->columnCount, count($labels));

        // Başlıklar her zaman metindir; hepsine başlık stili verilir.
        $this->addRow($labels, array_fill(0, count($labels), 'header'));
    }

    /**
     * Tek bir veri satırı ekler.
     *
     * @param array    $values Hücre değerleri (null → boş hücre)
     * @param string[] $types  Her hücrenin türü:
     *                         'text' | 'int' | 'money' | 'date' | 'header'
     */
    public function addRow(array $values, array $types = []): void
    {
        $this->rowCount++;
        $this->columnCount = max($this->columnCount, count($values));

        $cells = '';
        $index = 0;

        foreach ($values as $value) {
            $type = $types[$index] ?? 'text';
            $ref  = self::columnLetter($index) . $this->rowCount;

            $cells .= $this->buildCell($ref, $value, $type);
            $index++;
        }

        $this->rowsXml[] = '<row r="' . $this->rowCount . '">' . $cells . '</row>';
    }

    /**
     * Dosyayı üretir ve tarayıcıya indirme olarak gönderir.
     * Bu fonksiyondan sonra script SONLANIR (exit).
     *
     * @param string $filename Kullanıcının göreceği dosya adı
     */
    public function download(string $filename): void
    {
        $path = $this->buildFile();

        /* ÖNEMLİ – ÇIKTI TAMPONU:
         * Daha önce ekrana yazılmış tek bir boşluk veya BOM karakteri
         * bile ZIP paketinin başına eklenir ve Excel dosyayı
         * "bozuk" diye reddeder. Bu yüzden gönderim öncesi açık olan
         * tüm tamponları temizliyoruz. */
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // basename() : Dosya adına "../" karışmasını engeller.
        // Tırnak içinde göndermek, adında boşluk olan dosyaları korur.
        header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
        header('Content-Length: ' . (string) filesize($path));

        // Tarayıcı ve vekil sunucular eski bir kopyayı göstermesin.
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        readfile($path);

        // Geçici dosyayı diskte bırakmayın; zamanla temp klasörü şişer.
        @unlink($path);
        exit;
    }

    /**
     * Dosyayı üretir ve DİSKTEKİ yolunu döndürür.
     * (Testler veya e-posta eki için indirmeden üretmek gerekirse.)
     */
    public function save(string $targetPath): string
    {
        $temp = $this->buildFile();

        if (!@rename($temp, $targetPath)) {
            @unlink($temp);
            throw new RuntimeException('Excel dosyası hedefe taşınamadı: ' . $targetPath);
        }

        return $targetPath;
    }


    /* =================================================================
     *  İÇ İŞLEYİŞ
     * ============================================================== */

    /**
     * Altı XML parçasını üretip ZIP olarak paketler.
     *
     * @return string Geçici dosyanın tam yolu
     */
    private function buildFile(): string
    {
        // tempnam(): Çakışmayan geçici bir dosya adı üretir.
        $path = tempnam(sys_get_temp_dir(), 'xlsx');

        if ($path === false) {
            throw new RuntimeException('Geçici dosya oluşturulamadı.');
        }

        $zip = new ZipArchive();

        /* OVERWRITE : tempnam() dosyayı zaten oluşturduğu için CREATE
         * yetmez; var olan (0 baytlık) dosyanın üzerine yazmalıyız. */
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Excel paketi oluşturulamadı.');
        }

        $zip->addFromString('[Content_Types].xml',        $this->contentTypesXml());
        $zip->addFromString('_rels/.rels',                $this->relsXml());
        $zip->addFromString('xl/workbook.xml',            $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml',              $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml',   $this->sheetXml());

        $zip->close();

        return $path;
    }

    /**
     * Tek bir <c> (cell) etiketi üretir.
     *
     * HÜCRE TÜRLERİ:
     *   t="inlineStr" → Metin, doğrudan hücrenin içinde saklanır.
     *   (t yok)       → Sayı. Excel sayıları <v> içinde ham yazar.
     *
     * NEDEN inlineStr, sharedStrings DEĞİL?
     * Excel normalde metinleri xl/sharedStrings.xml adlı ortak bir
     * havuzda tutar ve hücrelerde sadece havuz numarasını yazar; bu,
     * aynı metin binlerce kez tekrar ediyorsa dosyayı küçültür.
     * inlineStr ise metni hücrenin içine yazar: dosya biraz büyür ama
     * kod çok daha basit ve anlaşılır olur. Bu örnek için doğru takas.
     */
    private function buildCell(string $ref, mixed $value, string $type): string
    {
        // Boş hücre: değeri hiç yazma, sadece stili koru.
        // (Excel'de boş hücre ile içinde "" olan hücre farklıdır;
        //  formüller ve filtreler bunları ayrı değerlendirir.)
        if ($value === null || $value === '') {
            $style = $type === 'header' ? self::STYLE_HEADER : $this->styleFor($type);
            return '<c r="' . $ref . '" s="' . $style . '"/>';
        }

        switch ($type) {
            case 'header':
                return '<c r="' . $ref . '" s="' . self::STYLE_HEADER . '" t="inlineStr">'
                     . '<is><t xml:space="preserve">' . self::escape((string) $value) . '</t></is></c>';

            case 'int':
                return '<c r="' . $ref . '" s="' . self::STYLE_INT . '">'
                     . '<v>' . (int) $value . '</v></c>';

            case 'money':
                /* Ondalık ayırıcı DAİMA nokta olmalıdır. Sunucunun yerel
                 * ayarı Türkçe ise (string)$float bazı ortamlarda "1234,5"
                 * üretir ve Excel dosyayı bozuk sayar. number_format()
                 * ile ayırıcıyı sabitleyip bu riski kapatıyoruz. */
                return '<c r="' . $ref . '" s="' . self::STYLE_MONEY . '">'
                     . '<v>' . number_format((float) $value, 2, '.', '') . '</v></c>';

            case 'date':
                $serial = self::dateToSerial((string) $value);

                // Tarih çözümlenemediyse veriyi kaybetmek yerine
                // olduğu gibi metin olarak yaz. Bu değer veritabanından
                // geldiği ve BİÇİMİ BOZUK olduğu için serbest metindir;
                // formül koruması burada da geçerlidir.
                if ($serial === null) {
                    return self::textCell($ref, (string) $value);
                }

                return '<c r="' . $ref . '" s="' . self::STYLE_DATE . '">'
                     . '<v>' . $serial . '</v></c>';

            case 'text':
            default:
                return self::textCell($ref, (string) $value);
        }
    }

    /**
     * Metin hücresi üretir ve gerekiyorsa FORMÜL KORUMASI uygular.
     */
    private static function textCell(string $ref, string $value): string
    {
        $style = self::needsFormulaGuard($value)
            ? self::STYLE_TEXT_GUARDED
            : self::STYLE_TEXT;

        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr">'
             . '<is><t xml:space="preserve">' . self::escape($value) . '</t></is></c>';
    }

    /**
     * Bu metin, Excel'de FORMÜLE dönüşme riski taşıyor mu?
     *
     * ---------------------------------------------------------------
     *  ÖLÇÜLEN DURUM (bu koruma eklenmeden önce)
     * ---------------------------------------------------------------
     *  Veritabanındaki bir "Departman" değerine `=cmd|'/c calc'!A0`
     *  yazıp dışa aktardık ve üretilen paketi açıp inceledik. Sonuç:
     *
     *      <c r="E52" s="2" t="inlineStr"><is><t>=cmd|&apos;/c calc&apos;!A0</t></is></c>
     *
     *  Yani hücre t="inlineStr" (METİN) olarak yazılıyor ve dosyada
     *  HİÇ <f> (formül) etiketi bulunmuyor. Bu ÖNEMLİ bir ayrımdır:
     *  bir .xlsx hücresi, ancak <f> etiketi varsa formüldür. Dolayısıyla
     *  bu dosya Excel'de AÇILDIĞINDA formül ÇALIŞMAZ — CSV'de yaşanan
     *  klasik "dosyayı açtım, komut çalıştı" senaryosu burada YOKTUR.
     *
     *  PEKİ NEDEN YİNE DE KORUYORUZ?
     *  Çünkü metin, hücrenin içinde HÂLÂ formül SÖZ DİZİMİNDEDİR ve
     *  Excel bir hücrenin içeriğini "yeniden girildiğinde" baştan
     *  yorumlar. Şu üç sıradan davranış onu canlı formüle çevirir:
     *
     *    1. Kullanıcı hücreye çift tıklar / F2'ye basıp Enter'lar,
     *    2. Sütunu kopyalayıp başka bir sayfaya yapıştırır,
     *    3. Veri ileride CSV olarak dışa aktarılır (CSV'de <f> ayrımı
     *       yoktur; "=" ile başlayan her hücre doğrudan formüldür).
     *
     *  Üçü de bu projeye dosya indiren SIRADAN bir kullanıcının
     *  yapabileceği şeylerdir. Saldırgan senaryosu şudur: içe aktarma
     *  ile serbest metin alanına (Departman) formül yazılır, sonra
     *  başka biri listeyi Excel'e aktarıp açar ve düzenlemeye çalışır.
     *  =HYPERLINK(...) ile veri sızdırılabilir, DDE söz dizimiyle
     *  (`cmd|'/c ...'`) komut çalıştırılması denenebilir.
     *
     * ---------------------------------------------------------------
     *  ÇÖZÜM: quotePrefix — apostrof EKLEMEDEN "bu metindir" demek
     * ---------------------------------------------------------------
     *  Yaygın tavsiye, değerin başına tek tırnak (') koymaktır. Bunu
     *  BİLEREK YAPMIYORUZ: .xlsx'te apostrof, Excel arayüzünün bir
     *  gösterim kuralıdır, hücre METNİNİN parçası değildir. Metnin
     *  başına gerçek bir apostrof yazsaydık kullanıcı hücrede
     *  `'=cmd...` görürdü — yani VERİYİ BOZARDIK.
     *
     *  OOXML'in doğru yolu, stile quotePrefix="1" koymaktır
     *  (ECMA-376, cellXfs/xf@quotePrefix). Bu, Excel'e "kullanıcı bu
     *  değeri baştan metin olarak girdi" der: hücre olduğu gibi
     *  görünür, değer bayt bayt korunur, ama yeniden girilse bile
     *  formüle dönüşmez.
     *
     *  KORUNAN KARAKTERLER: = + - @ ve sekme/satır başı. Son ikisi,
     *  Excel'in baştaki boşluk karakterlerini yok sayıp sonraki "="i
     *  görmesini engellemek içindir.
     *
     *  MEŞRU VERİ BOZULUR MU? HAYIR — ölçüldü:
     *  `-1250.75` (negatif sayı) ve `+90 555 111 22 33` (telefon)
     *  değerleri bu koruma ile de EKRANDA AYNEN görünür; yalnızca
     *  "kesinlikle metin" olarak işaretlenirler. Zaten para ve tarih
     *  sütunları 'money'/'date' türünden geçtiği için bu yola hiç
     *  girmez; gerçek sayılar sayı olarak yazılmaya devam eder.
     */
    private static function needsFormulaGuard(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true);
    }

    /** Tür adını stil numarasına çevirir. */
    private function styleFor(string $type): int
    {
        return match ($type) {
            'int'   => self::STYLE_INT,
            'money' => self::STYLE_MONEY,
            'date'  => self::STYLE_DATE,
            'text'  => self::STYLE_TEXT,
            default => self::STYLE_DEFAULT,
        };
    }

    /**
     * 0 tabanlı sütun numarasını Excel harfine çevirir.
     *   0 → A, 1 → B, 25 → Z, 26 → AA, 27 → AB ...
     *
     * 26'lık sayı sistemine benzer ama TAM DEĞİLDİR: sıfır basamağı
     * yoktur (A=1). Bu yüzden her adımda 1 çıkarmak gerekir.
     */
    public static function columnLetter(int $index): string
    {
        $letter = '';

        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $letter = chr(65 + ($i % 26)) . $letter;
        }

        return $letter;
    }

    /**
     * 'YYYY-MM-DD' tarihini Excel seri numarasına çevirir.
     * Çözümlenemezse null döner.
     */
    private static function dateToSerial(string $date): ?int
    {
        try {
            $epoch  = new DateTimeImmutable(self::EXCEL_EPOCH . ' 00:00:00', new DateTimeZone('UTC'));
            $target = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
        } catch (Exception $e) {
            return null;
        }

        // diff()->days her zaman POZİTİF döner; yönü invert alanı verir.
        $diff = $epoch->diff($target);
        $days = (int) $diff->days;

        return $diff->invert === 1 ? -$days : $days;
    }

    /**
     * Metni XML'e güvenle gömer.
     *
     * İki iş yapar:
     *   1. & < > " ' karakterlerini kaçışlar (yoksa XML bozulur)
     *   2. XML'in YASAKLADIĞI kontrol karakterlerini siler
     *
     * (2) neden gerekli? Veritabanındaki eski kayıtlarda \x00 gibi
     * görünmez karakterler bulunabilir. Bunlar kaçışlanamaz; dosyada
     * kalırlarsa Excel "onarılamayan içerik" uyarısı verir.
     */
    private static function escape(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Sayfa adını Excel kurallarına uydurur.
     * Excel'de sekme adı: en fazla 31 karakter ve : \ / ? * [ ] içeremez.
     */
    private function sanitizeSheetName(string $name): string
    {
        $name = str_replace([':', '\\', '/', '?', '*', '[', ']'], '', $name);
        $name = mb_substr(trim($name), 0, 31, 'UTF-8');

        return $name !== '' ? $name : 'Sayfa1';
    }


    /* =================================================================
     *  XML PARÇALARI
     * -----------------------------------------------------------------
     *  Aşağıdaki altı fonksiyon, paketin içindeki altı dosyayı üretir.
     *  İçerikleri OOXML standardının en sade hâlidir; ezberlemeniz
     *  gerekmez, kalıp olarak kullanabilirsiniz.
     * ============================================================== */

    /** Pakette hangi parçanın hangi türde olduğunu bildirir. */
    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    /** Paketin ana parçasının workbook.xml olduğunu söyler. */
    private function relsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            . ' Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    /** Çalışma kitabı: tek bir sayfamız var. */
    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::escape($this->sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    /** rId1 kimliğinin hangi dosyaya karşılık geldiğini bildirir. */
    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
            . ' Target="styles.xml"/>'
            . '</Relationships>';
    }

    /**
     * Stiller: sayı biçimleri, yazı tipleri, dolgular, kenarlıklar.
     *
     * DİKKAT – SIRALAMA KUTSALDIR:
     * Excel bu listelerde İSİM değil SIRA NUMARASI kullanır. Araya bir
     * eleman eklerseniz sonraki tüm numaralar kayar ve tablo bambaşka
     * görünür. Ayrıca fills listesinde ilk iki sıra (none ve gray125)
     * standart tarafından REZERVEDİR; kendi dolgunuz 2'den başlar.
     */
    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'

            /* Özel sayı biçimleri. 0-163 arası kimlikler Excel'in
             * yerleşik biçimleri için ayrılmıştır; kendi biçimlerimize
             * bu yüzden 164'ten başlayan numaralar veriyoruz. */
            . '<numFmts count="2">'
            . '<numFmt numFmtId="164" formatCode="dd\.mm\.yyyy"/>'
            . '<numFmt numFmtId="165" formatCode="#,##0.00"/>'
            . '</numFmts>'

            // 0 = normal metin, 1 = kalın beyaz (başlık için)
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'

            // 0 ve 1 rezerve; 2 = marka mavisi dolgu (#0b5cb5)
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid">'
            . '<fgColor rgb="FF0B5CB5"/><bgColor indexed="64"/>'
            . '</patternFill></fill>'
            . '</fills>'

            // 0 = kenarlıksız, 1 = dört tarafı ince gri çizgi
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border>'
            . '<left style="thin"><color rgb="FFDDDDDD"/></left>'
            . '<right style="thin"><color rgb="FFDDDDDD"/></right>'
            . '<top style="thin"><color rgb="FFDDDDDD"/></top>'
            . '<bottom style="thin"><color rgb="FFDDDDDD"/></bottom>'
            . '<diagonal/></border>'
            . '</borders>'

            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'

            /* ASIL STİL LİSTESİ. Sıra, sınıfın başındaki STYLE_*
             * sabitleriyle birebir aynıdır:
             *   0 varsayılan · 1 başlık · 2 metin · 3 tamsayı
             *   4 para       · 5 tarih  · 6 korumalı metin        */
            . '<cellXfs count="7">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0"'
            . ' applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"'
            . ' applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0"'
            . ' applyNumberFormat="1" applyBorder="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0"'
            . ' applyNumberFormat="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="center"/></xf>'

            /* 6 = KORUMALI METİN. Görsel olarak 2 numaralı metin
             * stiliyle birebir aynıdır; tek fark quotePrefix="1"
             * özniteliğidir. Excel bunu gören hücreyi "kullanıcı
             * metin olarak girdi" kabul eder ve içeriği yeniden
             * yorumlayıp formüle çevirmez.
             * Gerekçesi: needsFormulaGuard() açıklamasına bakın. */
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"'
            . ' applyBorder="1" quotePrefix="1"/>'
            . '</cellXfs>'

            . '</styleSheet>';
    }

    /**
     * Asıl veri sayfası.
     *
     * İki kullanıcı dostu ayrıntı içerir:
     *   - pane ... state="frozen" : Başlık satırı DONDURULUR, aşağı
     *     kaydırınca sütun adları ekranda kalır.
     *   - autoFilter              : Başlıklara filtre okları eklenir.
     *
     * DİKKAT: OOXML'de etiket SIRASI zorunludur. <autoFilter>
     * mutlaka <sheetData>'DAN SONRA gelmelidir; öne alırsanız Excel
     * dosyayı açmayı reddeder.
     */
    private function sheetXml(): string
    {
        $lastColumn = self::columnLetter(max(0, $this->columnCount - 1));

        /* --- Sütun genişlikleri --- */
        $cols = '';

        if ($this->columnWidths !== []) {
            $cols = '<cols>';

            foreach ($this->columnWidths as $i => $width) {
                // min/max 1 tabanlıdır (A sütunu = 1), bu yüzden +1.
                $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '"'
                       . ' width="' . (float) $width . '" customWidth="1"/>';
            }

            $cols .= '</cols>';
        }

        /* --- Başlık satırını dondur --- */
        // ySplit="1"     : İlk 1 satırın altından böl
        // topLeftCell    : Kayan bölgenin sol üst hücresi
        // state="frozen" : Bölme çizgisi sürüklenemez, sabit kalsın
        $sheetViews = '<sheetViews><sheetView workbookViewId="0">'
                    . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
                    . '</sheetView></sheetViews>';

        /* --- Filtre okları (sadece veri varsa) --- */
        $autoFilter = $this->rowCount > 1
            ? '<autoFilter ref="A1:' . $lastColumn . $this->rowCount . '"/>'
            : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $lastColumn . max(1, $this->rowCount) . '"/>'
            . $sheetViews
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $cols
            . '<sheetData>' . implode('', $this->rowsXml) . '</sheetData>'
            . $autoFilter
            . '</worksheet>';
    }
}
