{{-- Applies the sidebar width the person last dragged to before the page paints, so the sidebar doesn't jump on load. The key and limits match wagSidebarResize in resources/js/app.js, which keeps the width up to date. --}}
<script>
    (() => {
        try {
            const width = Number(window.localStorage.getItem('wag.sidebar-width'));

            if (width >= 200 && width <= 360) {
                document.documentElement.style.setProperty('--wag-sidebar-width', `${width}px`);
            }
        } catch {}
    })();
</script>
