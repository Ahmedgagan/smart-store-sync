// assets/js/photoswipe-simple-size-fix.js
(function(){
    // Run after DOM ready
    function onReady(fn){
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            setTimeout(fn, 0);
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    onReady(function(){
        try {
            var DEFAULT_W = 1200;
            var DEFAULT_H = 1200;

            // Find gallery images (works with standard WooCommerce markup)
            var gallery = document.querySelector('.woocommerce-product-gallery');
            if (!gallery) return;

            var items = gallery.querySelectorAll('.woocommerce-product-gallery__image');
            if (!items || !items.length) return;

            items.forEach(function(item){
                try {
                    var img = item.querySelector('img');
                    var anchor = item.querySelector('a');

                    // Prefer data-large_image on img, else img.src, else anchor.href
                    var large = (img && (img.getAttribute('data-large_image') || img.getAttribute('data-src') || img.getAttribute('src'))) || (anchor && anchor.getAttribute('href')) || null;
                    if (!large) return;

                    // Ensure anchor href points to the large image (PhotoSwipe expects the anchor href to match)
                    if (anchor && anchor.getAttribute('href') !== large) {
                        try { anchor.setAttribute('href', large); } catch(e){}
                    }

                    // If img already has non-zero data-large_image_width/height, skip preloading
                    var w = img && parseInt(img.getAttribute('data-large_image_width')||0, 10);
                    var h = img && parseInt(img.getAttribute('data-large_image_height')||0, 10);
                    if (w > 10 && h > 10) {
                        // also ensure data-large_image attribute exists
                        try { if (img && !img.getAttribute('data-large_image')) img.setAttribute('data-large_image', large); } catch(e){}
                        return;
                    }

                    // Preload the image to read natural dimensions
                    var preload = new Image();
                    var done = false;
                    var timeout = setTimeout(function(){
                        if (done) return;
                        done = true;
                        // fallback sizes
                        try {
                            if (img) {
                                img.setAttribute('data-large_image', large);
                                img.setAttribute('data-large_image_width', DEFAULT_W);
                                img.setAttribute('data-large_image_height', DEFAULT_H);
                            }
                            if (anchor && !anchor.getAttribute('href')) anchor.setAttribute('href', large);
                        } catch(e){}
                    }, 700); // 700ms timeout

                    preload.onload = function(){
                        if (done) return;
                        done = true;
                        clearTimeout(timeout);
                        var natW = preload.naturalWidth || DEFAULT_W;
                        var natH = preload.naturalHeight || DEFAULT_H;
                        try {
                            if (img) {
                                img.setAttribute('data-large_image', large);
                                img.setAttribute('data-large_image_width', natW);
                                img.setAttribute('data-large_image_height', natH);
                                // also set data-src/data-srcset empty to avoid weird srcset uses
                                if (!img.getAttribute('data-src')) img.setAttribute('data-src', large);
                            }
                            if (anchor && !anchor.getAttribute('href')) anchor.setAttribute('href', large);
                        } catch(e){}
                    };

                    preload.onerror = function(){
                        if (done) return;
                        done = true;
                        clearTimeout(timeout);
                        try {
                            if (img) {
                                img.setAttribute('data-large_image', large);
                                img.setAttribute('data-large_image_width', DEFAULT_W);
                                img.setAttribute('data-large_image_height', DEFAULT_H);
                            }
                            if (anchor && !anchor.getAttribute('href')) anchor.setAttribute('href', large);
                        } catch(e){}
                    };

                    // start loading
                    preload.src = large;

                } catch(inner){ /* per-item safe */ }
            });

        } catch(e){
            // fail silently — do not break page
            try { console && console.error && console.error('pswp size fix error', e); } catch(ignore){}
        }
    });
})();
