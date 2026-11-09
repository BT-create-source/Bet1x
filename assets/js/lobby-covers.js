// Keep far-off-screen cards out of the initial download queue.
(() => {
  const images = document.querySelectorAll('img.game-cover-card-bg[data-src]');
  const load = image => { image.src = image.dataset.src; };
  if (!('IntersectionObserver' in window)) { images.forEach(load); return; }
  const observer = new IntersectionObserver(entries => {
    for (const entry of entries) {
      if (!entry.isIntersecting) continue;
      load(entry.target);
      observer.unobserve(entry.target);
    }
  }, {rootMargin:'200px 0px'});
  images.forEach(image => observer.observe(image));
})();
