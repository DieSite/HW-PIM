{{--
    Keeps the scroll position on a reload, and after saving a form that
    redirects back to the same page. The browser's own restoration fails on
    the product edit screen: Vue, TinyMCE and the asset pickers render after
    load and change the page height, and a #sectie-… hash from the jump menu
    makes the browser jump to that anchor instead.

    Opening the page fresh (from a link) still starts at the top.
--}}
<script data-keep-scroll>
    (function () {
        const key = 'keep-scroll:' + location.pathname;
        const flagKey = 'keep-scroll:after-submit';
        const maxAgeMs = 30 * 60 * 1000;

        function read(storageKey) {
            try {
                return JSON.parse(sessionStorage.getItem(storageKey));
            } catch (error) {
                return null;
            }
        }

        function write(storageKey, value) {
            try {
                sessionStorage.setItem(storageKey, JSON.stringify(value));
            } catch (error) {
                /* Opslag geblokkeerd (privévenster): dan gewoon niet onthouden. */
            }
        }

        function savePosition() {
            write(key, {y: Math.round(window.scrollY), at: Date.now()});
        }

        let timer = null;
        window.addEventListener('scroll', function () {
            clearTimeout(timer);
            timer = setTimeout(savePosition, 150);
        }, {passive: true});

        window.addEventListener('pagehide', savePosition);

        document.addEventListener('submit', function (event) {
            if (event.defaultPrevented) {
                return;
            }

            savePosition();
            write(flagKey, {path: location.pathname, at: Date.now()});
        });

        const navigation = performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
        const type = navigation ? navigation.type : (performance.navigation && performance.navigation.type === 1 ? 'reload' : 'navigate');

        const flag = read(flagKey);
        const afterSubmit = flag && flag.path === location.pathname && Date.now() - flag.at < 60 * 1000;

        try {
            sessionStorage.removeItem(flagKey);
        } catch (error) {
            /* zie hierboven */
        }

        const saved = read(key);

        if (! saved || Date.now() - saved.at > maxAgeMs || ! (type === 'reload' || type === 'back_forward' || afterSubmit)) {
            return;
        }

        if ('scrollRestoration' in history) {
            history.scrollRestoration = 'manual';
        }

        /*
         * Zet de positie een paar seconden lang steeds opnieuw terug terwijl
         * de pagina nog groeit, tot je zelf gaat scrollen.
         */
        let userMoved = false;
        ['wheel', 'touchstart', 'keydown', 'mousedown'].forEach(function (name) {
            window.addEventListener(name, function () {
                userMoved = true;
            }, {once: true, passive: true});
        });

        const startedAt = Date.now();

        (function restore() {
            if (userMoved) {
                return;
            }

            if (Math.abs(window.scrollY - saved.y) > 2) {
                window.scrollTo(0, saved.y);
            }

            if (Date.now() - startedAt < 3000) {
                setTimeout(restore, 100);
            }
        })();
    })();
</script>
