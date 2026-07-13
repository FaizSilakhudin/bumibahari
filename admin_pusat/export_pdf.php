<?php
require '../config/koneksi.php';
require '../assets/fpdf186/fpdf.php';

$tgl_awal = $_GET['tgl_awal'] ?? date('Y-m-01');
$tgl_akhir = $_GET['tgl_akhir'] ?? date('Y-m-d');
$id_cabang = $_GET['id_cabang'] ?? '';

$where = "WHERE l.tanggal BETWEEN '$tgl_awal' AND '$tgl_akhir'";
$judul_cabang = 'Semua Cabang';
if($id_cabang != ''){
    $where .= " AND l.id_cabang = '".mysqli_real_escape_string($conn, $id_cabang)."'";
    $cabang_data = mysqli_fetch_assoc(mysqli_query($conn, "SELECT nama_cabang FROM cabang WHERE id_cabang='".mysqli_real_escape_string($conn, $id_cabang)."'"));
    $judul_cabang = $cabang_data['nama_cabang'] ?? 'Semua Cabang';
}

$query = "SELECT l.*, c.nama_cabang FROM laporan_cabang l JOIN cabang c ON l.id_cabang=c.id_cabang $where ORDER BY l.tanggal";
$result = mysqli_query($conn, $query);

// Total Akumulasi
$total_query = "SELECT 
    COALESCE(SUM(l.tunai),0) tunai, 
    COALESCE(SUM(l.qris),0) qris, 
    COALESCE(SUM(l.go_food),0) go, 
    COALESCE(SUM(l.grab_food),0) grab,
    COALESCE(SUM(l.total_omset),0) omzet, 
    COALESCE(SUM(l.belanja_pasar+l.belanja_sembako+l.belanja_beras+l.belanja_toko),0) belanja,
    COALESCE(SUM(l.sewa),0) sewa, 
    COALESCE(SUM(l.gaji),0) gaji, 
    COALESCE(SUM(l.listrik+l.air+l.sampah+l.keamanan+l.internet+l.lain_lain),0) lain,
    COALESCE(SUM(l.net_profit),0) net, 
    COALESCE(SUM(l.total_pengeluaran),0) pengeluaran
    FROM laporan_cabang l $where";
$total = mysqli_fetch_assoc(mysqli_query($conn, $total_query));

// Admin Fee = 3% dari Net Profit
$admin_fee = ($total['net'] ?? 0) * 0.03;
$kertas_nasi = 1800000;

class PDF extends FPDF {
    function Header() {
        $logo = '../assets/img/wbb.png';
        if(file_exists($logo)){
            $this->Image($logo, 10, 8, 35);
        }
        
        $this->SetFont('Times','B',14);
        $this->Cell(0,6,'WARTEG BUMI BAHARI',0,1,'C');
        $this->SetFont('Times','B',12);
        $this->SetTextColor(220,0,0);
        $this->Cell(0,6,'BUDAYA KULINER INDONESIA',0,1,'C');
        $this->SetTextColor(0,0,0);
        $this->Ln(2);
        
        $this->SetFont('Times','',9);
        $this->Cell(0,4,'WARTEG BUMI BAHARI',0,1,'L');
        $this->Cell(0,4,'Kecamatan Pamulang Kota Tangerang Selatan',0,1,'L');
        $this->Cell(0,4,'BANTEN 15417',0,1,'L');
        $this->Cell(0,4,'Phone : +62 858 2634 5875',0,1,'L');
        $this->Ln(2);
        
        $this->SetFont('Times','B',10);
        $this->Cell(25,5,'WBB Cabang',0,0,'L');
        $this->SetFont('Times','',10);
        $this->Cell(0,5,': '.$GLOBALS['judul_cabang'],0,1,'L');
        
        $this->SetFont('Times','B',10);
        $this->Cell(25,5,'Periode',0,0,'L');
        $this->SetFont('Times','',10);
        $this->Cell(0,5,': '.date('d/m/Y', strtotime($GLOBALS['tgl_awal'])).' s/d '.date('d/m/Y', strtotime($GLOBALS['tgl_akhir'])),0,1,'L');
        
        $this->SetFont('Times','B',10);
        $this->Cell(25,5,'Pengelola',0,0,'L');
        $this->SetFont('Times','',10);
        $this->Cell(0,5,': Sahidin',0,1,'L');
        
        $this->SetFont('Times','B',10);
        $this->Cell(25,5,'Investor',0,0,'L');
        $this->SetFont('Times','',10);
        $this->Cell(0,5,': Wardoyo',0,1,'L');
        
        $this->Ln(3);
        $this->SetFont('Times','B',10);
        $this->Cell(0,5,'Rekapitulasi Pendapatan & Pengeluaran',0,1,'L');
        $this->Ln(2);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Times','I',8);
        $this->Cell(0,10,'Halaman '.$this->PageNo(),0,0,'C');
    }
}

$pdf = new PDF('L','mm','A4');

// HALAMAN 1 - Tabel Rekap
$pdf->AddPage();
$pdf->SetFont('Times','',8);
$pdf->SetFillColor(200,220,255);
$pdf->Cell(8,7,'No',1,0,'C',true);
$pdf->Cell(22,7,'Tanggal',1,0,'C',true);
$pdf->Cell(25,7,'Tunai',1,0,'C',true);
$pdf->Cell(25,7,'QRIS',1,0,'C',true);
$pdf->Cell(25,7,'Go-Food',1,0,'C',true);
$pdf->Cell(25,7,'Grab-Food',1,0,'C',true);
$pdf->Cell(25,7,'OMZET',1,0,'C',true);
$pdf->Cell(25,7,'Belanja',1,0,'C',true);
$pdf->Cell(22,7,'Sewa Ruko',1,0,'C',true);
$pdf->Cell(25,7,'Gaji Karyawan',1,0,'C',true);
$pdf->Cell(22,7,'Lain-Lain',1,0,'C',true);
$pdf->Cell(25,7,'Net Profit',1,0,'C',true);
$pdf->Cell(15,7,'%',1,1,'C',true);

$pdf->SetFont('Times','',7);
$no=1;
mysqli_data_seek($result, 0);
while($d=mysqli_fetch_assoc($result)){
    $belanja = $d['belanja_pasar']+$d['belanja_sembako']+$d['belanja_beras']+$d['belanja_toko'];
    $lain = $d['listrik']+$d['air']+$d['sampah']+$d['keamanan']+$d['internet']+$d['lain_lain'];
    $margin = $d['total_omset']>0 ? ($d['net_profit']/$d['total_omset'])*100 : 0;
    
    $pdf->Cell(8,6,$no++,1,0,'C');
    $pdf->Cell(22,6,date('d/m/Y', strtotime($d['tanggal'])),1,0,'C');
    $pdf->Cell(25,6,number_format($d['tunai'],0,',','.'),1,0,'R');
    $pdf->Cell(25,6,number_format($d['qris'],0,',','.'),1,0,'R');
    $pdf->Cell(25,6,number_format($d['go_food'],0,',','.'),1,0,'R');
    $pdf->Cell(25,6,number_format($d['grab_food'],0,',','.'),1,0,'R');
    $pdf->Cell(25,6,number_format($d['total_omset'],0,',','.'),1,0,'R');
    $pdf->Cell(25,6,number_format($belanja,0,',','.'),1,0,'R');
    $pdf->Cell(22,6,number_format($d['sewa'],0,',','.'),1,0,'R');
    $pdf->Cell(25,6,number_format($d['gaji'],0,',','.'),1,0,'R');
    $pdf->Cell(22,6,number_format($lain,0,',','.'),1,0,'R');
    $pdf->Cell(25,6,number_format($d['net_profit'],0,',','.'),1,0,'R');
    $pdf->Cell(15,6,number_format($margin,2,',','.'),1,1,'R');
}

// JUMLAH
$pdf->SetFont('Times','B',8);
$pdf->SetFillColor(230,230,230);
$pdf->Cell(8,7,'JUMLAH',1,0,'C',true);
$pdf->Cell(22,7,'',1,0,'C',true);
$pdf->Cell(25,7,number_format($total['tunai'],0,',','.'),1,0,'R',true);
$pdf->Cell(25,7,number_format($total['qris'],0,',','.'),1,0,'R',true);
$pdf->Cell(25,7,number_format($total['go'],0,',','.'),1,0,'R',true);
$pdf->Cell(25,7,number_format($total['grab'],0,',','.'),1,0,'R',true);
$pdf->Cell(25,7,number_format($total['omzet'],0,',','.'),1,0,'R',true);
$pdf->Cell(25,7,number_format($total['belanja'],0,',','.'),1,0,'R',true);
$pdf->Cell(22,7,number_format($total['sewa'],0,',','.'),1,0,'R',true);
$pdf->Cell(25,7,number_format($total['gaji'],0,',','.'),1,0,'R',true);
$pdf->Cell(22,7,number_format($total['lain'],0,',','.'),1,0,'R',true);
$pdf->Cell(25,7,number_format($total['net'],0,',','.'),1,0,'R',true);
$pdf->Cell(15,7,number_format($total['omzet']>0 ? ($total['net']/$total['omzet'])*100 : 0,2,',','.'),1,1,'R',true);

// HALAMAN 2 - Uraian Beban Operasional
$pdf->AddPage();
$pdf->SetFont('Times','B',10);
$pdf->Cell(0,7,'Uraian Beban Operasional',0,1,'L');
$pdf->Ln(2);

$pdf->SetFont('Times','B',8);
$pdf->SetFillColor(200,220,255);
$pdf->Cell(8,7,'No',1,0,'C',true);
$pdf->Cell(50,7,'Uraian',1,0,'C',true);
$pdf->Cell(25,7,'Harian',1,0,'C',true);
$pdf->Cell(25,7,'Bulanan',1,0,'C',true);
$pdf->Cell(25,7,'Tahunan',1,0,'C',true);
$pdf->Cell(25,7,'Di Bayarkan',1,0,'C',true);
$pdf->Cell(25,7,'Jumlah',1,0,'C',true);
$pdf->Cell(40,7,'Keterangan',1,1,'C',true);

$hari = max(1, (strtotime($tgl_akhir) - strtotime($tgl_awal))/86400 + 1);
$sewa_harian = $total['sewa']/$hari;
$gaji_harian = $total['gaji']/$hari;
$listrik_harian = $total['lain']/5;

$pdf->SetFont('Times','',8);
$pdf->Cell(8,6,'1',1,0,'C');
$pdf->Cell(50,6,'Sewa Ruko',1,0,'L');
$pdf->Cell(25,6,number_format($sewa_harian,0,',','.'),1,0,'R');
$pdf->Cell(25,6,number_format($total['sewa'],0,',','.'),1,0,'R');
$pdf->Cell(25,6,number_format($total['sewa']*12,0,',','.'),1,0,'R');
$pdf->Cell(25,6,number_format($total['sewa'],0,',','.'),1,0,'R');
$pdf->Cell(25,6,number_format($total['sewa'],0,',','.'),1,0,'R');
$pdf->Cell(40,6,'31 Hari',1,1,'L');

$pdf->Cell(8,6,'2',1,0,'C');
$pdf->Cell(50,6,'Gaji Karyawan',1,0,'L');
$pdf->Cell(25,6,number_format($gaji_harian,0,',','.'),1,0,'R');
$pdf->Cell(25,6,number_format($total['gaji'],0,',','.'),1,0,'R');
$pdf->Cell(25,6,number_format($total['gaji']*12,0,',','.'),1,0,'R');
$pdf->Cell(25,6,number_format($total['gaji'],0,',','.'),1,0,'R');
$pdf->Cell(25,6,number_format($total['gaji'],0,',','.'),1,0,'R');
$pdf->Cell(40,6,'31 Hari Kerja',1,1,'L');

$pdf->Cell(8,6,'3',1,0,'C');
$pdf->Cell(50,6,'Listrik Prabayar',1,0,'L');
$pdf->Cell(25,6,number_format($listrik_harian,0,',','.'),1,0,'R');
$pdf->Cell(25,6,number_format($total['lain'],0,',','.'),1,0,'R');
$pdf->Cell(25,6,number_format($total['lain']*12,0,',','.'),1,0,'R');
$pdf->Cell(25,6,number_format($total['lain'],0,',','.'),1,0,'R');
$pdf->Cell(25,6,number_format($total['lain'],0,',','.'),1,0,'R');
$pdf->Cell(40,6,'',1,1,'L');

$pdf->Cell(8,6,'13',1,0,'C');
$pdf->SetFillColor(255,255,0);
$pdf->Cell(50,6,'Kertas Nasi WBB',1,0,'L',true);
$pdf->Cell(25,6,'',1,0,'R',true);
$pdf->Cell(25,6,number_format($kertas_nasi,0,',','.'),1,0,'R',true);
$pdf->Cell(25,6,number_format($kertas_nasi*12,0,',','.'),1,0,'R',true);
$pdf->Cell(25,6,number_format($kertas_nasi,0,',','.'),1,0,'R',true);
$pdf->Cell(25,6,number_format($kertas_nasi,0,',','.'),1,0,'R',true);
$pdf->Cell(40,6,'',1,1,'L',true);

$pdf->Cell(8,6,'14',1,0,'C');
$pdf->SetFillColor(255,255,0);
$pdf->Cell(50,6,'Admin Fee',1,0,'L',true);
$pdf->Cell(25,6,'',1,0,'R',true);
$pdf->Cell(25,6,number_format($admin_fee,0,',','.'),1,0,'R',true);
$pdf->Cell(25,6,number_format($admin_fee*12,0,',','.'),1,0,'R',true);
$pdf->Cell(25,6,number_format($admin_fee,0,',','.'),1,0,'R',true);
$pdf->Cell(25,6,number_format($admin_fee,0,',','.'),1,0,'R',true);
$pdf->Cell(40,6,'3% Dari Nett Profit',1,1,'L',true);

$pdf->SetFillColor(255,255,0);
$pdf->Cell(8,7,'',1,0,'C',true);
$pdf->Cell(50,7,'TOTAL BEBAN OPERASIONAL',1,0,'L',true);
$pdf->Cell(25,7,'',1,0,'R',true);
$pdf->Cell(25,7,'',1,0,'R',true);
$pdf->Cell(25,7,'',1,0,'R',true);
$pdf->Cell(25,7,'',1,0,'R',true);
$pdf->Cell(25,7,number_format($total['sewa']+$total['gaji']+$total['lain']+$kertas_nasi+$admin_fee,0,',','.'),1,0,'R',true);
$pdf->Cell(40,7,'',1,1,'L',true);

// AKUMULASI
$pdf->Ln(5);
$pdf->SetFont('Times','B',10);
$pdf->Cell(0,7,'AKUMULASI',0,1,'L');
$pdf->SetFont('Times','',9);
$pdf->Cell(80,7,'Uraian',1,0,'C');
$pdf->Cell(50,7,'Jumlah',1,0,'C');
$pdf->Cell(40,7,'Keterangan',1,1,'C');

$pdf->Cell(80,7,'Omzet',1,0,'L');
$pdf->Cell(50,7,number_format($total['omzet'],0,',','.'),1,0,'R');
$pdf->Cell(40,7,'',1,1,'L');

$pdf->Cell(80,7,'Pengeluaran Belanja',1,0,'L');
$pdf->Cell(50,7,number_format($total['belanja'],0,',','.'),1,0,'R');
$pdf->Cell(40,7,'',1,1,'L');

$pdf->Cell(80,7,'Beban Operasional',1,0,'L');
$pdf->Cell(50,7,number_format($total['sewa']+$total['gaji']+$total['lain'],0,',','.'),1,0,'R');
$pdf->Cell(40,7,'',1,1,'L');

$pdf->Cell(80,7,'Admin Fee',1,0,'L');
$pdf->Cell(50,7,number_format($admin_fee,0,',','.'),1,0,'R');
$pdf->Cell(40,7,'',1,1,'L');

$pdf->SetFont('Times','B',9);
$pdf->Cell(80,7,'Total Nett',1,0,'L');
$pdf->Cell(50,7,number_format($total['net']-$admin_fee,0,',','.'),1,0,'R');
$pdf->Cell(40,7,'',1,1,'L');

// REVENUE SHARING
$pdf->Ln(5);
$pdf->SetFont('Times','B',9);
$pdf->Cell(60,7,'Revenue Sharing',1,0,'C');
$pdf->Cell(30,7,'Persentase',1,0,'C');
$pdf->Cell(40,7,'Pembagian',1,0,'C');
$pdf->Cell(40,7,'Jumlah',1,1,'C');

$investor_share = ($total['net']-$admin_fee) * 0.5;
$pengelola_share = ($total['net']-$admin_fee) * 0.5;

$pdf->SetFont('Times','',9);
$pdf->Cell(60,7,'Investor',1,0,'L');
$pdf->Cell(30,7,'50%',1,0,'C');
$pdf->Cell(40,7,number_format($investor_share,0,',','.'),1,0,'R');
$pdf->Cell(40,7,number_format($investor_share,0,',','.'),1,1,'R');

$pdf->Cell(60,7,'Pengelola',1,0,'L');
$pdf->Cell(30,7,'50%',1,0,'C');
$pdf->Cell(40,7,number_format($pengelola_share,0,',','.'),1,0,'R');
$pdf->Cell(40,7,number_format($pengelola_share,0,',','.'),1,1,'R');

$pdf->Cell(60,7,'Admin',1,0,'L');
$pdf->Cell(30,7,'3%',1,0,'C');
$pdf->Cell(40,7,number_format($admin_fee,0,',','.'),1,0,'R');
$pdf->Cell(40,7,number_format($admin_fee,0,',','.'),1,1,'R');

// REKENING
$pdf->Ln(5);
$pdf->SetFillColor(255,255,0);
$pdf->SetFont('Times','B',9);
$pdf->Cell(40,7,'Nama',1,0,'C',true);
$pdf->Cell(30,7,'Jabatan',1,0,'C',true);
$pdf->Cell(40,7,'Nomor Rekening',1,0,'C',true);
$pdf->Cell(50,7,'Pemilik Rekening',1,0,'C',true);
$pdf->Cell(30,7,'Nama Bank',1,0,'C',true);
$pdf->Cell(40,7,'Total Nett',1,1,'C',true);

$pdf->SetFont('Times','',9);
$pdf->Cell(40,7,'Wardoyo',1,0,'L');
$pdf->Cell(30,7,'Investor',1,0,'C');
$pdf->Cell(40,7,'24000210026774',1,0,'C');
$pdf->Cell(50,7,'Wardoyo',1,0,'L');
$pdf->Cell(30,7,'Bank Nagari',1,0,'L');
$pdf->Cell(40,7,number_format($investor_share,0,',','.'),1,1,'R');

$pdf->Cell(40,7,'Sahidin',1,0,'L');
$pdf->Cell(30,7,'Pengelola',1,0,'C');
$pdf->Cell(40,7,'327031647',1,0,'C');
$pdf->Cell(50,7,'M. Sahidin Rahmatulloh',1,0,'L');
$pdf->Cell(30,7,'BCA',1,0,'L');
$pdf->Cell(40,7,number_format($pengelola_share,0,',','.'),1,1,'R');

$pdf->Cell(40,7,'Admin',1,0,'L');
$pdf->Cell(30,7,'Admin',1,0,'C');
$pdf->Cell(40,7,'1662598199',1,0,'C');
$pdf->Cell(50,7,'WBB',1,0,'L');
$pdf->Cell(30,7,'BCA',1,0,'L');
$pdf->Cell(40,7,number_format($admin_fee,0,',','.'),1,1,'R');

$pdf->Output('I','Laporan_WBB_'.$judul_cabang.'_'.date('F_Y', strtotime($tgl_awal)).'.pdf');