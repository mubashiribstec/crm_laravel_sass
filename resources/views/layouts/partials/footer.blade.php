<!-- ========== Footer Start ========== -->
<footer class="footer">
    <div class="footer-content" style="display: block; width: 100%; text-align: center; white-space: nowrap;">
        2019 - <script>document.write(new Date().getFullYear())</script>
        &copy; KINGSBURY PERSONNEL LTD. Developed by
        <img src="{{ asset('images/ibstec.png') }}" alt="IBSTEC" style="display: inline-block; vertical-align: middle; height: 20px; width: auto;">
        <a href="http://www.ibstec.com/" target="_blank" class="fw-bold footer-text">IBSTEC</a>
        <small class="text-muted"><i>Version-{{ \Horsefly\Setting::where('key', 'app_version')->value('value') }}</i></small>
    </div>
</footer>
<!-- ========== Footer End ========== -->
