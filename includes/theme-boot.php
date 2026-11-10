<?php
/* Terapkan tema pilihan pengguna sebelum halaman dicetak, supaya tidak ada
   kilatan terang -> gelap. Aman di-include berkali-kali (hanya jalan sekali). */
if (defined('PPF_THEME_BOOT')) return;
define('PPF_THEME_BOOT', true);
?>
<script>(function(){try{var t=localStorage.getItem('ppf_theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
