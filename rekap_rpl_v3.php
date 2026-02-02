<?php
/**
 * rekap_rpl_v3.php
 * ============================================================
 * REKAP RPL TERAKHIR (SEMUA TID) + LOKASI DARI TXT + FILTER + EXPORT
 *
 * FILE YANG DIPAKAI:
 * 1) tids.txt          => 1 baris 1 TID
 * 2) tids_lokasi.txt   => format: TID<TAB/SPACE>LOKASI (lokasi boleh ada spasi)
 *
 * FITUR:
 * - Hit API per TID (multi-curl)
 * - Ambil record RPL TERAKHIR per (TID + Jenis)
 * - Kolom tambahan: No, locate, tglRpl_terakhir, periode_opname_hari (contoh: "69 Hari")
 * - Filter: Durasi (>= x hari) + Search
 * - Export: ?export=1
 *   - Jika PhpSpreadsheet tersedia (composer) => XLSX
 *   - Jika tidak => CSV (tetap bisa dibuka Excel)
 *
 * UI:
 * - Tabel compact (padat, tidak tinggi), sticky header, scroll (seperti contoh kamu)
 * - Warna baris:
 *   - kuning = normal
 *   - merah muda = alert (durasi >= 60 hari) -> bisa kamu ubah
 */

date_default_timezone_set('Asia/Jakarta');

// ===============================
// CONFIG
// ===============================
$API_URL        = "http://203.153.103.122:89/cro_terpusat/api/get_rpl_tid.php";
$PERIODE_AWAL   = "2000-01-01";
$PERIODE_AKHIR  = date('Y-m-d');
$CONCURRENCY    = 15;

$TIDS_FILE      = __DIR__ . "/tids.txt";
$LOKASI_FILE    = __DIR__ . "/tids_lokasi.txt";

// alert threshold (warna merah)
$ALERT_HARI_MIN = 60;

// ===============================
// LOAD TID LIST
// ===============================
if (!file_exists($TIDS_FILE)) {
    http_response_code(500);
    die("File tids.txt tidak ditemukan. Buat file tids.txt (1 baris 1 TID) di folder yang sama.");
}
$tids = array_values(array_filter(array_map('trim', file($TIDS_FILE))));
if (empty($tids)) {
    http_response_code(500);
    die("tids.txt kosong.");
}

// ===============================
// LOAD LOKASI MAP (TID -> LOKASI)
// ===============================
$tidLokasiMap = [];
if (file_exists($LOKASI_FILE)) {
    $lines = file($LOKASI_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // split jadi 2 bagian saja: tid dan sisa lokasi
        $parts = preg_split('/\s+/', $line, 2);
        if (count($parts) >= 2) {
            $tid = trim($parts[0]);
            $lok = trim($parts[1]);
            if ($tid !== '' && $lok !== '') {
                $tidLokasiMap[$tid] = $lok;
            }
        }
    }
}

// ===============================
// HELPERS
// ===============================
function normalize_rows($decoded) {
    if (isset($decoded['data']) && is_array($decoded['data'])) return $decoded['data'];
    if (is_array($decoded) && isset($decoded[0]) && is_array($decoded[0])) return $decoded;
    return [];
}

function pick_date_value($row) {
    $candidates = ['tglRpl','tgl_rpl','tglRPL','tgl','tanggal','date','date_rpl','tanggal_rpl','date_rpl','dateRpl'];
    foreach ($candidates as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '') return (string)$row[$k];
    }
    foreach ($row as $k => $v) {
        $lk = strtolower((string)$k);
        if ((strpos($lk, 'tgl') !== false || strpos($lk, 'date') !== false) && trim((string)$v) !== '') {
            return (string)$v;
        }
    }
    return null;
}

function pick_jenis_value($row) {
    $candidates = ['jenis','Jenis','type','tipe','jns','jenis_rpl','JenisRpl'];
    foreach ($candidates as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '') return (string)$row[$k];
    }
    return '-';
}

function pick_locate_value_from_api($row) {
    // fallback kalau API sebenarnya punya field lokasi
    $candidates = ['locate','Locate','lokasi','Lokasi','location','Location','merchant_locate','merchantLocate','site','Site'];
    foreach ($candidates as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '') return (string)$row[$k];
    }
    return '';
}

function to_ymd($s) {
    if ($s === null) return null;
    $s = trim((string)$s);
    $ts = strtotime($s);
    if ($ts === false) return null;
    return date('Y-m-d', $ts);
}

function days_diff_from_today($ymd) {
    if (!$ymd) return null;
    $today = new DateTime(date('Y-m-d'));
    $d     = new DateTime($ymd);
    return (int)$d->diff($today)->format('%r%a');
}

function export_as_xlsx_if_possible($finalRows, $orderedCols) {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) return false;

    require $autoload;
    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) return false;

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // header
    $colIndex = 1;
    foreach ($orderedCols as $h) {
        $sheet->setCellValueByColumnAndRow($colIndex++, 1, $h);
    }

    // rows
    $rowIndex = 2;
    foreach ($finalRows as $r) {
        $colIndex = 1;
        foreach ($orderedCols as $h) {
            $sheet->setCellValueByColumnAndRow($colIndex++, $rowIndex, $r[$h] ?? '-');
        }
        $rowIndex++;
    }

    $filename = "rekap_rpl_" . date('Ymd_His') . ".xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

function export_as_csv($finalRows, $orderedCols) {
    $filename = "rekap_rpl_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$filename\"");

    $out = fopen('php://output', 'w');
    fputcsv($out, $orderedCols);

    foreach ($finalRows as $r) {
        $line = [];
        foreach ($orderedCols as $h) $line[] = $r[$h] ?? '-';
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

function hari_number($periodeOpname) {
    if (!is_string($periodeOpname)) return null;
    if (preg_match('/-?\d+/', $periodeOpname, $m)) return (int)$m[0];
    return null;
}

// ===============================
// MULTI CURL: hit API per TID (paralel)
// ===============================
$allRows = [];
$errors  = [];

$chunks = array_chunk($tids, $CONCURRENCY);

foreach ($chunks as $chunk) {
    $mh = curl_multi_init();
    $handles = [];

    foreach ($chunk as $tid) {
        $payload = json_encode([
            "tid" => $tid,
            "periodeAwal" => $PERIODE_AWAL,
            "periodeAkhir" => $PERIODE_AKHIR
        ]);

        $ch = curl_init($API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        curl_multi_add_handle($mh, $ch);
        $handles[(string)$tid] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 1.0);
    } while ($running > 0);

    foreach ($handles as $tid => $ch) {
        $resp = curl_multi_getcontent($ch);

        if ($resp === false || $resp === '') {
            $errors[] = "TID $tid: response kosong / gagal.";
        } else {
            $decoded = json_decode($resp, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = "TID $tid: gagal parsing JSON.";
            } else {
                $rows = normalize_rows($decoded);
                foreach ($rows as $r) {
                    if (!isset($r['tid'])) $r['tid'] = $tid;
                    $allRows[] = $r;
                }
            }
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
}

// ===============================
// GROUP: ambil tglRpl terbaru per (TID + Jenis)
// ===============================
$latest = [];
foreach ($allRows as $r) {
    $tid   = (string)($r['tid'] ?? '');
    if ($tid === '') continue;

    $jenis = pick_jenis_value($r);
    $tglYmd = to_ymd(pick_date_value($r));

    $key = $tid . "||" . $jenis;

    if (!isset($latest[$key])) {
        $r['_tglRplYmd'] = $tglYmd;
        $latest[$key] = $r;
    } else {
        $old = $latest[$key]['_tglRplYmd'] ?? null;
        if ($tglYmd && (!$old || $tglYmd > $old)) {
            $r['_tglRplYmd'] = $tglYmd;
            $latest[$key] = $r;
        }
    }
}

// ===============================
// FINAL ROWS + kolom custom
// ===============================
$finalRows = [];
foreach ($latest as $r) {
    $tid = (string)($r['tid'] ?? '-');
    $jenis = pick_jenis_value($r);

    $tgl = $r['_tglRplYmd'] ?? null;
    $diff = ($tgl ? days_diff_from_today($tgl) : null);

    $row = $r;

    $row['tid'] = $tid;
    $row['jenis'] = $jenis;

    // locate dari map dulu, fallback API, lalu '-'
    $lokTxt = $tidLokasiMap[$tid] ?? '';
    $lokApi = pick_locate_value_from_api($row);
    $row['locate'] = $lokTxt !== '' ? $lokTxt : ($lokApi !== '' ? $lokApi : '-');

    $row['tglRpl_terakhir'] = $tgl ?? '-';
    $row['periode_opname_hari'] = ($diff === null ? '-' : ($diff . " Hari"));

    unset($row['_tglRplYmd']);
    $finalRows[] = $row;
}

// sort desc berdasarkan angka hari (terlama di atas)
usort($finalRows, function($a, $b){
    $get = function($v){
        if (!is_string($v)) return -999999;
        if (!preg_match('/-?\d+/', $v, $m)) return -999999;
        return (int)$m[0];
    };
    return $get($b['periode_opname_hari'] ?? '') <=> $get($a['periode_opname_hari'] ?? '');
});

// ===============================
// KOLOM + NO
// ===============================
$priority = ['No','tid','locate','jenis','tglRpl_terakhir','periode_opname_hari'];
$cols = !empty($finalRows) ? array_keys($finalRows[0]) : [];
$ordered = [];

foreach ($priority as $p) {
    if ($p === 'No') { $ordered[] = 'No'; continue; }
    if (in_array($p, $cols, true)) $ordered[] = $p;
}
foreach ($cols as $c) if (!in_array($c, $ordered, true)) $ordered[] = $c;

$no = 1;
foreach ($finalRows as &$r) {
    $r['No'] = $no++;
    if (!isset($r['locate'])) $r['locate'] = '-';
    if (!isset($r['periode_opname_hari'])) $r['periode_opname_hari'] = '-';
    if (!isset($r['tglRpl_terakhir'])) $r['tglRpl_terakhir'] = '-';
}
unset($r);

// ===============================
// EXPORT
// ===============================
if (isset($_GET['export']) && $_GET['export'] == '1') {
    $ok = export_as_xlsx_if_possible($finalRows, $ordered);
    if ($ok === false) export_as_csv($finalRows, $ordered);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Rekap RPL Terakhir (Semua TID)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    /* ===== TABLE COMPACT STYLE (mirip contoh kamu) ===== */
    .table-wrap {
        max-height: 72vh;           /* jangan memanjang kebawah */
        overflow: auto;             /* scroll vertikal & horizontal */
        border: 1px solid #ddd;
        border-radius: 8px;
        background: #fff;
    }
    table.rpl-table {
        font-size: 12px;
        white-space: nowrap;        /* jangan turun baris */
        margin: 0;
    }
    table.rpl-table th,
    table.rpl-table td {
        padding: 4px 8px;
        vertical-align: middle;
        border: 1px solid #ddd;
    }
    table.rpl-table thead th {
        position: sticky;
        top: 0;
        background: #f8f9fa;
        z-index: 2;
        text-align: center;
        font-weight: 700;
    }
    tr.row-normal { background-color: #ffffb3; } /* kuning */
    tr.row-alert  { background-color: #ffb3b3; } /* merah muda */
    td.num { text-align: right; }
    td.center { text-align: center; }
  </style>
</head>
<body class="bg-light">
<div class="container-fluid px-3 py-3">

  <div class="d-flex align-items-center gap-2 mb-1">
    <h4 class="m-0">📊 Rekap RPL Terakhir (Semua TID)</h4>
  </div>

  <div class="text-muted mb-3">
    Periode query: <?= htmlspecialchars($PERIODE_AWAL) ?> s/d <?= htmlspecialchars($PERIODE_AKHIR) ?> |
    Total TID: <?= count($tids) ?> |
    Hasil (TID+Jenis): <?= count($finalRows) ?> |
    Alert: ≥ <?= (int)$ALERT_HARI_MIN ?> hari
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-warning">
      <div class="fw-bold mb-1">Ada beberapa error saat ambil data:</div>
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if (!empty($finalRows)): ?>
    <div class="card p-3">

      <!-- FILTERS + EXPORT -->
      <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <div class="input-group" style="max-width:240px;">
          <span class="input-group-text">Durasi</span>
          <select id="durasiFilter" class="form-select">
            <option value="">Semua</option>
            <option value="7">≥ 7 Hari</option>
            <option value="14">≥ 14 Hari</option>
            <option value="30">≥ 30 Hari</option>
            <option value="60">≥ 60 Hari</option>
            <option value="90">≥ 90 Hari</option>
          </select>
        </div>

        <div class="input-group" style="max-width:320px;">
          <span class="input-group-text">Search</span>
          <input id="globalSearch" class="form-control" placeholder="ketik tid / lokasi / jenis / status / dll">
        </div>

        <a class="btn btn-success ms-auto" href="?export=1">⬇️ Download Excel</a>
      </div>

      <div class="table-wrap">
        <table id="rplTable" class="rpl-table table table-sm align-middle">
          <thead>
            <tr>
              <?php foreach ($ordered as $col): ?>
                <th><?= htmlspecialchars($col) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($finalRows as $row): ?>
              <?php
                $hari = hari_number($row['periode_opname_hari'] ?? '');
                $rowClass = ($hari !== null && $hari >= $ALERT_HARI_MIN) ? 'row-alert' : 'row-normal';
              ?>
              <tr class="<?= $rowClass ?>">
                <?php foreach ($ordered as $col): ?>
                  <?php
                    $val = $row[$col] ?? '-';

                    // styling kolom angka (opsional)
                    $isNumCol = preg_match('/(^no$|jumlah|jml|amt|amount|nominal|pagu|rp|persen|%)/i', $col);
                    $isCenterCol = preg_match('/(^no$|tid$|jenis$|status$|order_status$|time|jam)/i', $col);

                    $cls = [];
                    if ($isNumCol && is_numeric(str_replace([',',' '], '', (string)$val))) $cls[] = 'num';
                    if ($isCenterCol) $cls[] = 'center';
                  ?>
                  <td class="<?= implode(' ', $cls) ?>"><?= htmlspecialchars((string)$val) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="small text-muted mt-2">
        * Export: kalau server punya PhpSpreadsheet (composer) → <b>.xlsx</b>, kalau tidak → otomatis <b>.csv</b>.
      </div>

    </div>
  <?php else: ?>
    <div class="alert alert-danger">Tidak ada data yang berhasil ditampilkan.</div>
  <?php endif; ?>

</div>

<script>
function getHariNumber(text) {
  if (!text) return NaN;
  const m = String(text).match(/-?\d+/);
  return m ? parseInt(m[0], 10) : NaN;
}

function colIndexByName(table, name) {
  const ths = Array.from(table.querySelectorAll("thead th")).map(th => th.innerText.trim());
  return ths.findIndex(x => x === name);
}

function applyFilters() {
  const table = document.getElementById("rplTable");
  if (!table) return;

  const idxDurasi = colIndexByName(table, "periode_opname_hari");

  const minHari = document.getElementById('durasiFilter').value;
  const q = document.getElementById('globalSearch').value.trim().toLowerCase();

  const rows = document.querySelectorAll('#rplTable tbody tr');
  rows.forEach(tr => {
    const tds = Array.from(tr.querySelectorAll('td'));
    const allText = tds.map(td => td.innerText.toLowerCase()).join(' ');

    const durasiText = (idxDurasi >= 0 && tds[idxDurasi]) ? tds[idxDurasi].innerText : '';
    const hari = getHariNumber(durasiText);

    const okDurasi = !minHari || (!Number.isNaN(hari) && hari >= parseInt(minHari, 10));
    const okSearch = !q || allText.includes(q);

    tr.style.display = (okDurasi && okSearch) ? '' : 'none';
  });
}

document.getElementById('durasiFilter')?.addEventListener('change', applyFilters);
document.getElementById('globalSearch')?.addEventListener('input', applyFilters);
</script>

</body>
</html>
