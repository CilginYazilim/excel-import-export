<?php
/**
 * =====================================================================
 *  XLSX OKUYUCU – Bağımlılıksız Excel (.xlsx) Çözümleyici
 *  cilginyazilim.com – PHP Excel Dışa/İçe Aktarma
 * ---------------------------------------------------------------------
 *  XlsxWriter'ın tersini yapar: ZIP paketini açar, içindeki XML'leri
 *  okur ve size düz bir PHP dizisi verir:
 *
 *      [
 *        ['Ad', 'Soyad', 'E-posta'],          ← 1. satır (başlıklar)
 *        ['Evren', 'ÇILGIN', 'e@ornek.com'],  ← 2. satır
 *        ...
 *      ]
 *
 *  ÜÇ ZOR NOKTA VE ÇÖZÜMLERİ:
 *
 *  1) METİNLER HÜCREDE DEĞİLDİR.
 *     Excel, tekrar eden metinleri xl/sharedStrings.xml adlı ortak bir
 *     havuzda tutar; hücrede sadece havuz SIRA NUMARASI yazar
 *     (t="s"). Bu yüzden önce havuzu okuyup belleğe alırız.
 *
 *  2) TARİHLER SAYIDIR.
 *     "15.08.2026" hücrede 46249 olarak durur. Bir sayının tarih mi
 *     yoksa gerçekten sayı mı olduğunu ancak HÜCRENİN STİLİNE bakarak
 *     anlayabilirsiniz. Bu yüzden xl/styles.xml içindeki sayı
 *     biçimlerini de çözümleyip "hangi stil tarih gösteriyor"
 *     listesini çıkarıyoruz.
 *
 *  3) BOŞ HÜCRELER YAZILMAZ.
 *     B sütunu boşsa dosyada <c r="B2"> etiketi HİÇ BULUNMAZ; satır
 *     doğrudan A'dan C'ye atlar. Hücreleri sırayla okursanız sütunlar
 *     kayar. Çözüm: her hücrenin "r" özniteliğindeki harfe bakıp
 *     sütun numarasını hesaplamak ve aradaki boşlukları doldurmak.
 *
 *  KULLANIMI:
 *      $rows = XlsxReader::read('/yol/dosya.xlsx');
 * =====================================================================
 */

declare(strict_types=1);

final class XlsxReader
{
    /** Excel tarih başlangıcı (XlsxWriter ile aynı olmalıdır). */
    private const EXCEL_EPOCH = '1899-12-30';

    /**
     * ZIP BOMBASINA KARŞI ÜST SINIR.
     *
     * 1 MB'lık bir ZIP, açıldığında gigabaytlarca veri üretecek şekilde
     * hazırlanabilir ("zip bomb"). Paketi açmadan ÖNCE, arşivin
     * bildirdiği açılmış toplam boyuta bakıp sınırı aşanı reddediyoruz.
     *
     * SINIR NEDEN 64 MB'DAN 24 MB'A İNDİRİLDİ? (ölçümle)
     * Sınır 64 MB iken şöyle bir dosya hazırladık: 169 KB'lık bir
     * yükleme, açıldığında 55 MB'lık bir sharedStrings.xml üretiyordu
     * ve eski sınırın ALTINDA kaldığı için kabul ediliyordu. Sonuç:
     * PHP'nin tepe bellek kullanımı 88 MB'a çıktı — 169 KB'lık bir
     * istek için ~500 kat büyüme.
     *
     * Bu ölümcül değildi (varsayılan memory_limit=512M ile sunucu
     * ayakta kaldı), ama gereksizdi: içe aktarma sınırı 5 MB
     * (IMPORT_MAX_BYTES) ve 2000 satır. Meşru bir dosyanın 24 MB'ın
     * üzerinde açılmış içeriğe ihtiyacı yoktur — ölçtük: gerçek
     * 10.000 satırlık bir .xlsx yalnızca 313 KB, açılmış hâli birkaç
     * MB. Sınırı işin gerçek boyutuna çekmek, saldırganın elindeki
     * büyütme payını dörtte bire indirir.
     *
     * NEDEN "bildirilen" boyuta güveniyoruz? Arşivin başlığındaki
     * boyut saldırgan tarafından KÜÇÜK gösterilebilir; ama o durumda
     * PHP açarken veriyi bildirilen boyutta keser, yani yalan söylemek
     * saldırganın işine yaramaz.
     */
    private const MAX_UNCOMPRESSED_BYTES = 24 * 1024 * 1024;

    /**
     * Excel'in YERLEŞİK tarih/saat biçim numaraları.
     *
     * 0-163 arası numaralar standarttır ve dosyada tanımlanmazlar;
     * hangisinin tarih olduğunu bilmek zorundayız. Bu liste OOXML
     * standardından alınmıştır (14-22 tarih/saat, 45-47 süre).
     */
    private const BUILTIN_DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47];

    /**
     * Bir .xlsx dosyasının İLK sayfasını okur.
     *
     * @param  string $path       Dosyanın diskteki tam yolu
     * @param  int    $maxRows    En fazla kaç satır okunacak (0 = sınırsız)
     * @return array<int,array<int,string>> Satır → sütun dizisi
     *
     * @throws RuntimeException Dosya geçerli bir xlsx değilse
     */
    public static function read(string $path, int $maxRows = 0): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(
                'PHP "zip" eklentisi etkin değil. php.ini içinde extension=zip satırını açın.'
            );
        }

        if (!is_file($path)) {
            throw new RuntimeException('Excel dosyası bulunamadı.');
        }

        $zip = new ZipArchive();

        /* --- DOĞRULAMA 1: Gerçekten ZIP mi? ---------------------------
         * Uzantısı .xlsx olan bir metin dosyası buradan geçemez.
         * Dosya türünü UZANTIDAN değil İÇERİKTEN doğruluyoruz. */
        if ($zip->open($path) !== true) {
            throw new RuntimeException(
                'Dosya açılamadı. Geçerli bir .xlsx dosyası seçtiğinizden emin olun.'
            );
        }

        try {
            self::guardAgainstZipBomb($zip);

            /* --- DOĞRULAMA 2: Excel paketi mi? ------------------------
             * Sıradan bir ZIP'in içinde xl/workbook.xml bulunmaz. */
            if ($zip->locateName('xl/workbook.xml') === false) {
                throw new RuntimeException(
                    'Bu dosya bir Excel çalışma kitabı değil. '
                    . 'Lütfen .xlsx biçiminde kaydedilmiş bir dosya yükleyin.'
                );
            }

            $sheetPath    = self::findFirstSheetPath($zip);
            $sharedStrings = self::readSharedStrings($zip);
            $dateStyles    = self::readDateStyles($zip);

            return self::readSheet($path, $sheetPath, $sharedStrings, $dateStyles, $maxRows);
        } finally {
            // finally: Hata olsa da olmasa da arşiv MUTLAKA kapatılır.
            // Aksi halde dosya Windows'ta kilitli kalır ve silinemez.
            $zip->close();
        }
    }

    /**
     * Excel seri numarasını 'YYYY-MM-DD' tarihine çevirir.
     * Bu, XlsxWriter::dateToSerial()'in tersidir.
     *
     * Ondalık kısım günün saatidir (0.5 = öğlen); tarih alanına
     * yazacağımız için yok sayıyoruz.
     */
    public static function serialToDate(float $serial): ?string
    {
        // 1 = 31.12.1899. Sıfır ve negatif değerler anlamsızdır.
        // 2958465 = 31.12.9999, Excel'in üst sınırı.
        if ($serial < 1 || $serial > 2958465) {
            return null;
        }

        try {
            $epoch = new DateTimeImmutable(self::EXCEL_EPOCH . ' 00:00:00', new DateTimeZone('UTC'));

            return $epoch->modify('+' . (int) floor($serial) . ' days')->format('Y-m-d');
        } catch (Exception $e) {
            return null;
        }
    }


    /* =================================================================
     *  İÇ İŞLEYİŞ
     * ============================================================== */

    /** Açılmış boyut toplamı sınırı aşıyorsa işlemi durdurur. */
    private static function guardAgainstZipBomb(ZipArchive $zip): void
    {
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $total += (int) $stat['size'];

            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('Dosya içeriği beklenenden çok büyük; işlem durduruldu.');
            }
        }
    }

    /**
     * İlk sayfanın paket içindeki yolunu bulur.
     *
     * NEDEN SABİT 'xl/worksheets/sheet1.xml' YAZMIYORUZ?
     * Bu ad bir GARANTİ DEĞİLDİR. Google Sheets, LibreOffice veya
     * bazı dışa aktarma araçları farklı adlar üretir. Doğru yol,
     * workbook.xml'deki ilk sayfanın r:id'sini alıp bunu
     * workbook.xml.rels dosyasındaki hedefle eşleştirmektir.
     */
    private static function findFirstSheetPath(ZipArchive $zip): string
    {
        $workbook = self::loadXml($zip, 'xl/workbook.xml');

        $relationId = '';

        if (isset($workbook->sheets->sheet[0])) {
            // r:id özniteliği "r" ad alanındadır; attributes()'a ad
            // alanı URI'sini vermeden okunamaz.
            $attributes = $workbook->sheets->sheet[0]->attributes(
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
            );

            $relationId = isset($attributes['id']) ? (string) $attributes['id'] : '';
        }

        if ($relationId !== '') {
            $rels = self::loadXml($zip, 'xl/_rels/workbook.xml.rels');

            foreach ($rels->Relationship as $relationship) {
                if ((string) $relationship['Id'] !== $relationId) {
                    continue;
                }

                $target = (string) $relationship['Target'];

                // Hedef "worksheets/sheet1.xml" (göreli) ya da
                // "/xl/worksheets/sheet1.xml" (mutlak) olabilir.
                $target = ltrim($target, '/');

                if (!str_starts_with($target, 'xl/')) {
                    $target = 'xl/' . $target;
                }

                if ($zip->locateName($target) !== false) {
                    return $target;
                }
            }
        }

        // Son çare: en yaygın ad.
        if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
            return 'xl/worksheets/sheet1.xml';
        }

        throw new RuntimeException('Excel dosyasında okunabilir bir sayfa bulunamadı.');
    }

    /**
     * Ortak metin havuzunu (sharedStrings.xml) diziye çevirir.
     *
     * Bir metin parçalanmış biçimde saklanabilir: kelimenin bir kısmı
     * kalınsa Excel onu ayrı <r> (run) etiketlerine böler. Bu yüzden
     * tek bir <t> okumak yetmez, tüm <t> parçalarını birleştiririz.
     */
    private static function readSharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            // Tüm metinler inlineStr ise bu dosya hiç oluşmaz; hata değildir.
            return [];
        }

        $xml     = self::loadXml($zip, 'xl/sharedStrings.xml');
        $strings = [];

        foreach ($xml->si as $item) {
            if (isset($item->t)) {
                // Bölünmemiş, düz metin.
                $strings[] = (string) $item->t;
                continue;
            }

            // Biçimlendirme yüzünden parçalanmış metin: parçaları birleştir.
            $buffer = '';

            foreach ($item->r as $run) {
                $buffer .= (string) $run->t;
            }

            $strings[] = $buffer;
        }

        return $strings;
    }

    /**
     * Hangi stil numaralarının TARİH gösterdiğini bulur.
     *
     * Yol: styles.xml → cellXfs listesindeki her stilin numFmtId'sine
     * bakılır. Bu numara ya Excel'in yerleşik tarih biçimlerinden
     * biridir (14, 15, 22...) ya da dosyada tanımlanmış özel bir
     * biçimdir ("dd.mm.yyyy" gibi).
     *
     * @return array<int,bool> [stilNumarası => true]
     */
    private static function readDateStyles(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/styles.xml') === false) {
            return [];
        }

        $xml = self::loadXml($zip, 'xl/styles.xml');

        /* --- 1) Dosyaya özel biçimlerden tarih olanları bul --- */
        $customDateFormats = [];

        if (isset($xml->numFmts)) {
            foreach ($xml->numFmts->numFmt as $format) {
                $id   = (int) $format['numFmtId'];
                $code = (string) $format['formatCode'];

                if (self::looksLikeDateFormat($code)) {
                    $customDateFormats[$id] = true;
                }
            }
        }

        /* --- 2) Stil listesini gez, tarih biçimi kullananları işaretle --- */
        $dateStyles = [];
        $index      = 0;

        if (isset($xml->cellXfs)) {
            foreach ($xml->cellXfs->xf as $xf) {
                $formatId = (int) ($xf['numFmtId'] ?? 0);

                if (in_array($formatId, self::BUILTIN_DATE_FORMATS, true)
                    || isset($customDateFormats[$formatId])) {
                    $dateStyles[$index] = true;
                }

                $index++;
            }
        }

        return $dateStyles;
    }

    /**
     * Bir biçim kodunun tarih biçimi olup olmadığını anlar.
     *
     * "#,##0.00 TL" → hayır      "dd.mm.yyyy" → evet
     *
     * İNCELİK: Biçim kodunda tırnak içine alınmış metinler ve ters
     * bölü ile kaçışlanmış karakterler, BİÇİM DEĞİL sabit metindir.
     * Örneğin `0" gün"` kodundaki "gün" içinde "n" harfi vardır ama
     * bu bir tarih biçimi değildir. Bu yüzden önce sabit metinleri
     * temizler, sonra geriye kalanda tarih harfleri ararız.
     */
    private static function looksLikeDateFormat(string $code): bool
    {
        // Tırnak içindeki sabit metinleri at: "TL", " gün" ...
        $code = preg_replace('/"[^"]*"/', '', $code) ?? $code;

        // Ters bölü ile kaçışlanmış tek karakterleri at: \g \ü ...
        $code = preg_replace('/\\\\./', '', $code) ?? $code;

        // [Red] gibi renk/koşul bloklarını at.
        $code = preg_replace('/\[[^\]]*\]/', '', $code) ?? $code;

        // Geriye kalanda y (yıl), d (gün), h (saat) veya s (saniye)
        // varsa bu bir tarih/saat biçimidir.
        // NOT: "m" tek başına aranmaz; hem ay hem dakika anlamına
        // gelir ve "#,##0" gibi biçimlerde yanlış eşleşme yapabilir.
        return preg_match('/[ydhs]/i', $code) === 1;
    }

    /**
     * Sayfa XML'ini satır satır okur.
     *
     * NEDEN XMLReader?
     * SimpleXML tüm belgeyi belleğe alır; 50.000 satırlık bir sayfada
     * yüzlerce megabayt tüketebilir. XMLReader ise imleç gibi
     * ilerler ve yalnızca o anki satırı bellekte tutar. Her satırı
     * ayrı ayrı SimpleXML'e vererek iki yaklaşımın iyi yanını
     * birleştiriyoruz: düşük bellek + okunaklı kod.
     */
    private static function readSheet(
        string $zipPath,
        string $sheetPath,
        array $sharedStrings,
        array $dateStyles,
        int $maxRows
    ): array {
        $reader = new XMLReader();

        /* zip:// sarmalayıcısı, arşivi diske açmadan içindeki tek bir
         * dosyayı akış olarak okumamızı sağlar. "#" işaretinden sonra
         * paket içindeki yol yazılır. */
        if (!@$reader->open('zip://' . $zipPath . '#' . $sheetPath)) {
            throw new RuntimeException('Excel sayfası okunamadı.');
        }

        $rows = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'row') {
                    continue;
                }

                // Satırın tamamını al ve SimpleXML ile çözümle.
                $rowXml = $reader->readOuterXml();

                if ($rowXml === '') {
                    continue;
                }

                $rows[] = self::parseRow($rowXml, $sharedStrings, $dateStyles);

                if ($maxRows > 0 && count($rows) >= $maxRows) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }

    /**
     * Tek bir <row> etiketini sütun dizisine çevirir.
     *
     * @return array<int,string>
     */
    private static function parseRow(string $rowXml, array $sharedStrings, array $dateStyles): array
    {
        $row = self::parseXmlString($rowXml);

        $cells     = [];
        $maxColumn = -1;

        foreach ($row->c as $cell) {
            /* Hücrenin "r" özniteliği "C7" gibi bir adrestir.
             * Harf kısmı sütunu verir. Bu adres OLMADAN sıraya
             * güvenmek, boş hücreler yüzünden sütun kaymasına yol açar
             * (dosyanın başındaki 3. maddeye bakın). */
            $reference   = (string) $cell['r'];
            $columnIndex = $reference !== ''
                ? self::columnIndex($reference)
                : $maxColumn + 1;

            $cells[$columnIndex] = self::cellValue($cell, $sharedStrings, $dateStyles);
            $maxColumn = max($maxColumn, $columnIndex);
        }

        /* Eksik sütunları boş metinle doldur ki her satır aynı
         * uzunlukta olsun ve $row[2] her zaman 3. sütunu göstersin. */
        $result = [];

        for ($i = 0; $i <= $maxColumn; $i++) {
            $result[$i] = $cells[$i] ?? '';
        }

        return $result;
    }

    /**
     * Bir hücrenin değerini metin olarak döndürür.
     *
     * Hücre türleri ("t" özniteliği):
     *   s         → sharedStrings havuzundaki sıra numarası
     *   inlineStr → metin hücrenin içinde (<is><t>)
     *   str       → formül sonucu metin
     *   b         → mantıksal (1 = DOĞRU, 0 = YANLIŞ)
     *   e         → hata (#DIV/0! gibi) → boş kabul edilir
     *   (yok)     → sayı; stili tarih biçimindeyse tarihe çevrilir
     */
    private static function cellValue(SimpleXMLElement $cell, array $sharedStrings, array $dateStyles): string
    {
        $type = (string) ($cell['t'] ?? '');

        switch ($type) {
            case 's':
                $index = (int) $cell->v;
                return $sharedStrings[$index] ?? '';

            case 'inlineStr':
                if (!isset($cell->is)) {
                    return '';
                }

                if (isset($cell->is->t)) {
                    return (string) $cell->is->t;
                }

                // Biçimlendirme yüzünden parçalanmış metin.
                $buffer = '';

                foreach ($cell->is->r as $run) {
                    $buffer .= (string) $run->t;
                }

                return $buffer;

            case 'str':
                return (string) $cell->v;

            case 'b':
                return ((string) $cell->v) === '1' ? '1' : '0';

            case 'e':
                // Hücrede #YOK / #DIV/0! gibi bir hata var.
                // İçe aktarmada bunu boş kabul etmek, doğrulamanın
                // "zorunlu alan boş" demesini sağlar; sessizce
                // anlamsız bir metin kaydetmekten iyidir.
                return '';

            default:
                if (!isset($cell->v)) {
                    return '';
                }

                $raw = (string) $cell->v;

                /* SAYI MI, TARİH Mİ?
                 * Hücrenin stil numarası (s) tarih biçimleri listesinde
                 * varsa, bu sayı aslında bir tarihtir. */
                $styleIndex = isset($cell['s']) ? (int) $cell['s'] : 0;

                if (isset($dateStyles[$styleIndex]) && is_numeric($raw)) {
                    $date = self::serialToDate((float) $raw);

                    if ($date !== null) {
                        return $date;
                    }
                }

                return $raw;
        }
    }

    /**
     * "C7" gibi bir hücre adresinden 0 tabanlı sütun numarasını çıkarır.
     *   A → 0, B → 1, Z → 25, AA → 26
     * (XlsxWriter::columnLetter()'ın tersidir.)
     */
    private static function columnIndex(string $reference): int
    {
        // Adresin harf kısmını al: "AB12" → "AB"
        if (preg_match('/^([A-Z]+)/i', $reference, $matches) !== 1) {
            return 0;
        }

        $letters = strtoupper($matches[1]);
        $index   = 0;

        // 26'lık sistem, ama sıfır basamağı yok: A=1, Z=26, AA=27
        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    /** Paketten bir XML parçasını okuyup SimpleXML nesnesine çevirir. */
    private static function loadXml(ZipArchive $zip, string $entry): SimpleXMLElement
    {
        $content = $zip->getFromName($entry);

        if ($content === false) {
            throw new RuntimeException('Excel dosyasında beklenen parça bulunamadı: ' . $entry);
        }

        return self::parseXmlString($content);
    }

    /**
     * XML metnini güvenle çözümler.
     *
     * XXE SALDIRISI NEDİR?
     * Saldırgan, XML'in başına bir "varlık" (entity) tanımı koyup
     * sunucudaki dosyaları okutabilir:
     *
     *   <!DOCTYPE x [ <!ENTITY gizli SYSTEM "file:///etc/passwd"> ]>
     *
     * PHP 8'de dış varlıkların yüklenmesi varsayılan olarak KAPALIDIR;
     * ayrıca LIBXML_NONET bayrağıyla ağ erişimini de açıkça kapatıp
     * bu kapıyı iki kez kilitliyoruz. LIBXML_NOENT'i ASLA eklemeyin;
     * o bayrak varlıkları çözümleyerek açığı geri açar.
     */
    private static function parseXmlString(string $content): SimpleXMLElement
    {
        // Kendi hata yönetimimizi kullanabilmek için libxml
        // uyarılarını bastır; sonra eski ayara geri dön.
        $previous = libxml_use_internal_errors(true);

        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new RuntimeException('Excel dosyasının içeriği okunamadı (bozuk XML).');
        }

        return $xml;
    }
}
