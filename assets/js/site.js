$(function () {
    var cartDrawer = document.getElementById('cartDrawer');
    var cartDrawerContent = document.getElementById('cartDrawerContent');

    if (cartDrawer && cartDrawerContent) {
        function refreshCartDrawer() {
            cartDrawerContent.innerHTML = '<p class="text-muted">Loading cart…</p>';
            fetch('cart_panel.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) {
                    if (!response.ok) throw new Error('Unable to load your cart.');
                    return response.text();
                })
                .then(function (html) {
                    cartDrawerContent.innerHTML = html;
                    var content = cartDrawerContent.querySelector('[data-cart-count]');
                    var badge = document.getElementById('cartCountBadge');
                    if (content && badge) {
                        var count = Number(content.getAttribute('data-cart-count')) || 0;
                        badge.textContent = count;
                        badge.hidden = count === 0;
                    }
                })
                .catch(function (error) {
                    cartDrawerContent.innerHTML = '<p class="text-danger"></p>';
                    cartDrawerContent.querySelector('.text-danger').textContent = error.message;
                });
        }

        cartDrawer.addEventListener('shown.bs.offcanvas', refreshCartDrawer);
        cartDrawerContent.addEventListener('submit', function (event) {
            var form = event.target.closest('.cart-drawer-action');
            if (!form) return;
            event.preventDefault();

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok) throw new Error(data.error || 'Unable to update your cart.');
                        return data;
                    });
                })
                .then(refreshCartDrawer)
                .catch(function (error) {
                    var message = document.createElement('p');
                    message.className = 'alert alert-danger';
                    message.textContent = error.message;
                    cartDrawerContent.prepend(message);
                });
        });

        if (new URLSearchParams(window.location.search).has('open_cart')) {
            bootstrap.Offcanvas.getOrCreateInstance(cartDrawer).show();
            var cleanUrl = new URL(window.location.href);
            cleanUrl.searchParams.delete('open_cart');
            cleanUrl.searchParams.delete('buy_now');
            window.history.replaceState({}, '', cleanUrl);
        }
    }

    var $menu = $('#sideMenu');
    var $hotspot = $('#menuHotspot');

    if ($menu.length && $hotspot.length) {
        var hideTimer;

        function openMenu() {
            clearTimeout(hideTimer);
            $menu.removeClass('collapsed').addClass('open');
        }

        function closeMenu() {
            clearTimeout(hideTimer);
            hideTimer = setTimeout(function () {
                $menu.removeClass('open').addClass('collapsed');
                $menu.find('.has-sub').removeClass('open');
            }, 120);
        }

        // Hover the invisible strip on the far-left edge to open the menu.
        $hotspot.on('mouseenter', openMenu);

        // Keep the menu open while the mouse is inside it.
        $menu.on('mouseenter', function () {
            clearTimeout(hideTimer);
        });

        // Hide it when the mouse leaves the menu.
        $menu.on('mouseleave', closeMenu);

        // Expand/collapse Categories.
        $menu.on('click', '.category-toggle', function (event) {
            event.preventDefault();
            $(this).closest('.has-sub').toggleClass('open');
        });

        // Close after selecting a normal menu link.
        $menu.on('click', 'a:not(.category-toggle)', function () {
            closeMenu();
        });

        // Escape closes the menu.
        $(document).on('keydown', function (event) {
            if (event.key === 'Escape') {
                closeMenu();
            }
        });
    }

    var $carousel = $('#homeCarousel');
    if (!$carousel.length) return;

    var $slides = $carousel.find('.slide');
    var currentIndex = 0;
    var autoTimer;

    function showSlide(index) {
        currentIndex = (index + $slides.length) % $slides.length;
        $slides.removeClass('active').hide();
        $slides.eq(currentIndex).addClass('active').show();
    }

    function nextSlide() {
        showSlide(currentIndex + 1);
    }

    function previousSlide() {
        showSlide(currentIndex - 1);
    }

    function startAutoplay() {
        clearInterval(autoTimer);
        autoTimer = setInterval(nextSlide, 3000);
    }

    $carousel.find('.next').on('click', function (event) {
        event.preventDefault();
        nextSlide();
        startAutoplay();
    });

    $carousel.find('.prev').on('click', function (event) {
        event.preventDefault();
        previousSlide();
        startAutoplay();
    });

    $carousel.on('mouseenter', function () {
        clearInterval(autoTimer);
    }).on('mouseleave', function () {
        startAutoplay();
    });

    showSlide(0);
    startAutoplay();
});
