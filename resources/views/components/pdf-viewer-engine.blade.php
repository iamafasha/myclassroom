{{--
    Shared pdf.js rendering engine for the student viewer and the editor preview.

    Handles: fit-to-width by default, pinch/ctrl-wheel/double-tap zoom, retina-sharp
    rendering, lazy page rendering and a "current page" readout. Mount it with
    <x-pdf-viewer-engine /> and drive it through window.createPdfViewer().
--}}
@once
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        if (window.pdfjsLib) {
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        }

        (function () {
            if (window.createPdfViewer) return;

            const MIN_SCALE = 0.2;
            const MAX_SCALE = 8;
            const MAX_CANVAS_PIXELS = 12e6;
            const PAGE_STYLE = 'overflow:hidden;background:#ffffff;position:relative;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);';
            const BADGE_STYLE = 'position:absolute;top:8px;right:8px;z-index:2;background:rgba(17,24,39,0.72);'
                + 'color:#ffffff;font-size:11px;font-weight:600;padding:3px 8px;border-radius:999px;'
                + 'pointer-events:none;font-variant-numeric:tabular-nums;';

            function clamp(value) {
                return Math.min(MAX_SCALE, Math.max(MIN_SCALE, value));
            }

            /**
             * opts.wrapper        horizontally scrolling box around the pages
             * opts.sizer          child of wrapper, sized during a pinch to keep scroll extents right
             * opts.container      flex column that holds the page elements
             * opts.levelEl        optional zoom-percentage readout
             * opts.pageNoEl       optional "Page X of Y" readout
             * opts.toolbar        optional sticky toolbar (its height is excluded from the visible strip)
             * opts.verticalScroll 'self' when wrapper scrolls vertically, otherwise the nearest
             *                     scrollable ancestor is used
             * opts.pageBadges     set false to hide the per-page number badge
             */
            window.createPdfViewer = function (opts) {
                const wrapper = opts.wrapper;
                const sizer = opts.sizer;
                const container = opts.container;
                const levelEl = opts.levelEl || null;
                const pageNoEl = opts.pageNoEl || null;
                const toolbar = opts.toolbar || null;
                const showBadges = opts.pageBadges !== false;

                // The page rarely scrolls on <body> here — the app shell scrolls inside a panel —
                // so every anchor adjustment has to target the real scroll parent.
                const scrollParent = opts.verticalScroll === 'self' ? wrapper : (function () {
                    let node = wrapper.parentElement;
                    while (node && node !== document.body) {
                        const overflowY = getComputedStyle(node).overflowY;
                        if (overflowY === 'auto' || overflowY === 'scroll') return node;
                        node = node.parentElement;
                    }
                    return null;
                })();

                let pdfDoc = null;
                let pageObjs = {};
                let pageEls = {};
                let startPage = 1;
                let endPage = 1;
                let startPercent = 0;
                let endPercent = 100;
                let scale = 1;
                let fitScale = 1;
                let userZoomed = false;
                let gesture = null;
                let renderTimer = null;
                let pageTicking = false;
                let loadToken = 0;

                /* ---------- geometry helpers ---------- */

                function scrollByY(delta) {
                    if (!delta) return;
                    if (scrollParent) scrollParent.scrollTop += delta;
                    else window.scrollBy(0, delta);
                }

                // Visible strip of the viewer, minus whatever the sticky toolbar covers.
                function viewBounds() {
                    let top = 0;
                    let bottom = window.innerHeight;
                    if (scrollParent) {
                        const rect = scrollParent.getBoundingClientRect();
                        top = Math.max(top, rect.top);
                        bottom = Math.min(bottom, rect.bottom);
                    }
                    if (toolbar) top += toolbar.offsetHeight;
                    return {top: top, bottom: bottom};
                }

                function contentPadding() {
                    const styles = getComputedStyle(container);
                    return parseFloat(styles.paddingLeft) + parseFloat(styles.paddingRight);
                }

                // Largest scale at which the widest page still fits the available width.
                function computeFitScale() {
                    let widest = 0;
                    Object.keys(pageObjs).forEach(function (num) {
                        widest = Math.max(widest, pageObjs[num].getViewport({scale: 1}).width);
                    });
                    if (!widest) return 1;
                    // Fill the width, never leaving more than 10% of it to the gutters.
                    const available = Math.max(120, wrapper.clientWidth - contentPadding(), wrapper.clientWidth * 0.9);
                    return clamp(available / widest);
                }

                function pageMetrics(num, atScale) {
                    const viewport = pageObjs[num].getViewport({scale: atScale});
                    const topPct = (num === startPage) ? startPercent : 0;
                    const bottomPct = (num === endPage) ? endPercent : 100;
                    return {
                        viewport: viewport,
                        topSkip: viewport.height * (topPct / 100),
                        visibleHeight: Math.max(1, viewport.height * ((bottomPct - topPct) / 100))
                    };
                }

                /* ---------- rendering ---------- */

                // Resize the page boxes right away; an already rendered canvas is stretched by CSS
                // (blurry for a moment) until the crisp re-render lands, so zooming never blanks out.
                function layoutPage(num) {
                    const el = pageEls[num];
                    const metrics = pageMetrics(num, scale);
                    el.style.width = metrics.viewport.width + 'px';
                    el.style.height = metrics.visibleHeight + 'px';

                    const canvas = el.querySelector('canvas');
                    if (canvas) {
                        canvas.style.width = metrics.viewport.width + 'px';
                        canvas.style.height = metrics.viewport.height + 'px';
                        canvas.style.marginTop = (-metrics.topSkip) + 'px';
                    }
                }

                function layoutAll() {
                    Object.keys(pageEls).forEach(function (num) { layoutPage(parseInt(num)); });
                }

                function renderPage(num) {
                    const el = pageEls[num];
                    const page = pageObjs[num];
                    if (!el || !page) return;
                    if (el.dataset.renderedScale === String(scale)) return;
                    if (el._task) { el._task.cancel(); el._task = null; }

                    const target = scale;
                    const metrics = pageMetrics(num, target);
                    const viewport = metrics.viewport;

                    // Render at device pixel density for sharp text, capped so deep zoom
                    // levels don't blow up memory.
                    let output = window.devicePixelRatio || 1;
                    const pixels = viewport.width * viewport.height * output * output;
                    if (pixels > MAX_CANVAS_PIXELS) {
                        output = Math.max(1, output * Math.sqrt(MAX_CANVAS_PIXELS / pixels));
                    }

                    const canvas = document.createElement('canvas');
                    canvas.width = Math.max(1, Math.floor(viewport.width * output));
                    canvas.height = Math.max(1, Math.floor(viewport.height * output));
                    canvas.style.display = 'block';
                    canvas.style.width = viewport.width + 'px';
                    canvas.style.height = viewport.height + 'px';
                    canvas.style.marginTop = (-metrics.topSkip) + 'px';

                    const task = page.render({
                        canvasContext: canvas.getContext('2d'),
                        viewport: viewport,
                        transform: output !== 1 ? [output, 0, 0, output, 0, 0] : null
                    });
                    el._task = task;

                    task.promise.then(function () {
                        el._task = null;
                        if (!pageEls[num]) return;
                        if (target !== scale) { renderPage(num); return; }

                        const old = el.querySelector('canvas');
                        if (old) el.removeChild(old);
                        el.appendChild(canvas);
                        el.dataset.renderedScale = String(target);
                    }).catch(function (err) {
                        el._task = null;
                        if (err && err.name === 'RenderingCancelledException') return;
                        console.error(err);
                    });
                }

                function renderVisible() {
                    Object.keys(pageEls).forEach(function (num) {
                        if (pageEls[num]._visible) renderPage(parseInt(num));
                    });
                }

                function scheduleRender() {
                    clearTimeout(renderTimer);
                    renderTimer = setTimeout(renderVisible, 160);
                }

                // Pages far outside the viewport stay unrendered until they are scrolled near.
                const observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        entry.target._visible = entry.isIntersecting;
                        if (entry.isIntersecting) renderPage(parseInt(entry.target.dataset.page));
                    });
                }, {root: scrollParent, rootMargin: '800px 0px'});

                /* ---------- readouts ---------- */

                function updateLevel() {
                    if (levelEl) levelEl.textContent = Math.round((scale / (fitScale || 1)) * 100) + '%';
                }

                // The page covering most of the visible strip is the "current" one.
                function updateCurrentPage() {
                    if (!pageNoEl) return;

                    const nums = Object.keys(pageEls);
                    if (!nums.length) return;

                    const bounds = viewBounds();
                    let best = null;
                    let bestVisible = -1;

                    nums.forEach(function (num) {
                        const rect = pageEls[num].getBoundingClientRect();
                        const visible = Math.min(rect.bottom, bounds.bottom) - Math.max(rect.top, bounds.top);
                        if (visible > bestVisible) {
                            bestVisible = visible;
                            best = parseInt(num);
                        }
                    });

                    if (best === null || bestVisible <= 0) return;
                    pageNoEl.textContent = 'Page ' + best + ' of ' + (pdfDoc ? pdfDoc.numPages : endPage);
                }

                function requestPageUpdate() {
                    if (pageTicking) return;
                    pageTicking = true;
                    requestAnimationFrame(function () {
                        pageTicking = false;
                        updateCurrentPage();
                    });
                }

                /* ---------- zooming ---------- */

                // Zoom while keeping the content under (clientX, clientY) pinned in place.
                function setScale(next, clientX, clientY) {
                    next = clamp(next);
                    if (Math.abs(next - scale) < 0.001) return;

                    const wrapperRect = wrapper.getBoundingClientRect();
                    if (clientX === undefined) clientX = wrapperRect.left + wrapper.clientWidth / 2;
                    if (clientY === undefined) {
                        const bounds = viewBounds();
                        clientY = (Math.max(wrapperRect.top, bounds.top) + Math.min(wrapperRect.bottom, bounds.bottom)) / 2;
                    }

                    const before = sizer.getBoundingClientRect();
                    const dx = clientX - before.left;
                    const dy = clientY - before.top;
                    const factor = next / scale;

                    scale = next;
                    layoutAll();

                    const after = sizer.getBoundingClientRect();
                    wrapper.scrollLeft += after.left - (clientX - dx * factor);
                    scrollByY(after.top - (clientY - dy * factor));

                    updateLevel();
                    scheduleRender();
                    requestPageUpdate();
                }

                /* ---------- touch gestures ---------- */

                function touchDistance(touches) {
                    return Math.hypot(
                        touches[0].clientX - touches[1].clientX,
                        touches[0].clientY - touches[1].clientY
                    );
                }

                function touchMid(touches) {
                    return {
                        x: (touches[0].clientX + touches[1].clientX) / 2,
                        y: (touches[0].clientY + touches[1].clientY) / 2
                    };
                }

                // A live pinch is a cheap CSS transform; the real re-render only happens once the
                // fingers lift, which keeps the gesture at 60fps even on long documents.
                function beginGesture(event) {
                    const touches = [event.touches[0], event.touches[1]];
                    const mid = touchMid(touches);

                    gesture = {
                        startDistance: touchDistance(touches),
                        k: 1,
                        originRect: sizer.getBoundingClientRect(),
                        anchorX: mid.x,
                        anchorY: mid.y,
                        naturalWidth: container.offsetWidth,
                        naturalHeight: container.offsetHeight
                    };

                    // Pin the layout width: the sizer is about to be widened for the scroll
                    // extent, and the pages must not re-flow inside it.
                    container.style.width = gesture.naturalWidth + 'px';
                    container.style.transformOrigin = 'top left';
                    container.style.willChange = 'transform';
                }

                function updateGesture(event) {
                    const touches = [event.touches[0], event.touches[1]];
                    const distance = touchDistance(touches);
                    if (!gesture.startDistance || !distance) return;

                    const k = clamp(scale * (distance / gesture.startDistance)) / scale;
                    gesture.k = k;

                    container.style.transform = 'scale(' + k + ')';
                    sizer.style.width = (gesture.naturalWidth * k) + 'px';
                    sizer.style.height = (gesture.naturalHeight * k) + 'px';

                    // Pin the content point the pinch started on to the current midpoint, so two
                    // fingers pan the document as well as scale it.
                    const mid = touchMid(touches);
                    const contentX = gesture.anchorX - gesture.originRect.left;
                    const contentY = gesture.anchorY - gesture.originRect.top;
                    const rect = sizer.getBoundingClientRect();
                    wrapper.scrollLeft += rect.left - (mid.x - contentX * k);
                    scrollByY(rect.top - (mid.y - contentY * k));
                }

                function endGesture() {
                    const k = gesture.k;
                    gesture = null;

                    const before = sizer.getBoundingClientRect();
                    container.style.transform = '';
                    container.style.willChange = '';
                    container.style.width = '';
                    sizer.style.width = '';
                    sizer.style.height = '';

                    scale = clamp(scale * k);
                    userZoomed = true;
                    layoutAll();

                    // Bake the transform into the layout without the view jumping.
                    const after = sizer.getBoundingClientRect();
                    wrapper.scrollLeft += after.left - before.left;
                    scrollByY(after.top - before.top);

                    updateLevel();
                    scheduleRender();
                    requestPageUpdate();
                }

                let lastTap = 0;
                let lastTapX = 0;
                let lastTapY = 0;

                wrapper.addEventListener('touchstart', function (event) {
                    if (event.touches.length === 2) {
                        event.preventDefault();
                        beginGesture(event);
                    }
                }, {passive: false});

                wrapper.addEventListener('touchmove', function (event) {
                    if (gesture && event.touches.length >= 2) {
                        event.preventDefault();
                        updateGesture(event);
                    }
                }, {passive: false});

                wrapper.addEventListener('touchend', function (event) {
                    if (gesture && event.touches.length < 2) {
                        endGesture();
                        lastTap = 0;
                        return;
                    }

                    if (event.changedTouches.length !== 1 || event.touches.length !== 0) return;

                    const touch = event.changedTouches[0];
                    const now = Date.now();
                    const isDoubleTap = now - lastTap < 300
                        && Math.hypot(touch.clientX - lastTapX, touch.clientY - lastTapY) < 30;

                    if (isDoubleTap) {
                        event.preventDefault();
                        lastTap = 0;
                        const zoomedIn = scale > fitScale * 1.05;
                        userZoomed = !zoomedIn;
                        setScale(zoomedIn ? fitScale : fitScale * 2, touch.clientX, touch.clientY);
                    } else {
                        lastTap = now;
                        lastTapX = touch.clientX;
                        lastTapY = touch.clientY;
                    }
                }, {passive: false});

                wrapper.addEventListener('touchcancel', function () {
                    if (gesture) endGesture();
                });

                // Ctrl/⌘ + wheel, which is also what a trackpad pinch reports.
                wrapper.addEventListener('wheel', function (event) {
                    if (!event.ctrlKey && !event.metaKey) return;
                    event.preventDefault();
                    userZoomed = true;
                    setScale(scale * Math.exp(-event.deltaY * 0.0025), event.clientX, event.clientY);
                }, {passive: false});

                (scrollParent || window).addEventListener('scroll', requestPageUpdate, {passive: true});
                window.addEventListener('resize', requestPageUpdate, {passive: true});

                // Keep filling the width when the column or window changes size.
                let resizeTimer = null;
                function onResize() {
                    clearTimeout(resizeTimer);
                    resizeTimer = setTimeout(function () {
                        if (!Object.keys(pageEls).length) return;
                        const previousFit = fitScale;
                        fitScale = computeFitScale();
                        if (!userZoomed) setScale(fitScale);
                        else if (previousFit) setScale(scale * (fitScale / previousFit));
                        updateLevel();
                    }, 150);
                }

                if (window.ResizeObserver) new ResizeObserver(onResize).observe(wrapper);
                else window.addEventListener('resize', onResize);

                /* ---------- public API ---------- */

                function reset() {
                    Object.keys(pageEls).forEach(function (num) {
                        const el = pageEls[num];
                        if (el._task) { el._task.cancel(); el._task = null; }
                        observer.unobserve(el);
                    });
                    pageEls = {};
                    pageObjs = {};
                    container.style.transform = '';
                    container.style.width = '';
                    sizer.style.width = '';
                    sizer.style.height = '';
                    container.innerHTML = '';
                }

                function build() {
                    for (let num = startPage; num <= endPage; num++) {
                        const el = document.createElement('div');
                        el.setAttribute('style', PAGE_STYLE);
                        el.dataset.page = num;
                        el._visible = false;

                        if (showBadges) {
                            const badge = document.createElement('span');
                            badge.setAttribute('style', BADGE_STYLE);
                            badge.textContent = 'Page ' + num;
                            el.appendChild(badge);
                        }

                        pageEls[num] = el;
                        container.appendChild(el);
                        observer.observe(el);
                    }

                    fitScale = computeFitScale();
                    if (!userZoomed) scale = fitScale;
                    layoutAll();
                    updateLevel();
                    renderVisible();
                    updateCurrentPage();
                }

                return {
                    /** Show a placeholder/error message instead of pages. */
                    message: function (html) {
                        reset();
                        container.innerHTML = html;
                        if (pageNoEl) pageNoEl.textContent = 'Page –';
                    },

                    /** Render `pdf`, restricted to the given page range and crop percentages. */
                    setDocument: function (pdf, range) {
                        const token = ++loadToken;
                        range = range || {};

                        pdfDoc = pdf;
                        startPage = Math.max(1, parseInt(range.startPage) || 1);
                        endPage = parseInt(range.endPage) || pdf.numPages;
                        if (endPage > pdf.numPages) endPage = pdf.numPages;
                        startPercent = Number.isFinite(range.startPercent) ? range.startPercent : 0;
                        endPercent = Number.isFinite(range.endPercent) ? range.endPercent : 100;

                        reset();

                        if (startPage > endPage) {
                            container.innerHTML = '<p style="color:#6B7280;font-size:13px;padding:16px;">No pages to display.</p>';
                            return Promise.resolve();
                        }

                        const nums = [];
                        for (let num = startPage; num <= endPage; num++) nums.push(num);

                        return Promise.all(nums.map(function (num) {
                            return pdf.getPage(num).then(function (page) {
                                if (token === loadToken) pageObjs[num] = page;
                            });
                        })).then(function () {
                            if (token !== loadToken) return;
                            build();
                        });
                    },

                    zoomBy: function (factor) {
                        userZoomed = true;
                        setScale(scale * factor);
                    },

                    fitWidth: function () {
                        userZoomed = false;
                        fitScale = computeFitScale();
                        setScale(fitScale);
                        updateLevel();
                    }
                };
            };
        })();
    </script>
@endonce
