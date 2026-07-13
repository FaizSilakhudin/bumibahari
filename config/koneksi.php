<?php
$conn = mysqli_connect("localhost","root","","db_warteg_bumi_bahari");
if(!$conn){ die("Koneksi gagal: ".mysqli_connect_error()); }
session_start();
?>