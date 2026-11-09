// Visionneuse : clavier (← → Échap) et glissement du doigt sur mobile
(function () {
  function aller(id) {
    var a = document.getElementById(id);
    if (a) window.location.href = a.href;
  }
  document.addEventListener('keydown', function (e) {
    if (e.target.tagName === 'VIDEO') return;
    if (e.key === 'ArrowLeft') aller('prec');
    else if (e.key === 'ArrowRight') aller('suiv');
    else if (e.key === 'Escape') aller('fermer');
  });
  var x0 = null, y0 = null;
  document.addEventListener('touchstart', function (e) {
    x0 = e.touches[0].clientX; y0 = e.touches[0].clientY;
  }, { passive: true });
  document.addEventListener('touchend', function (e) {
    if (x0 === null) return;
    var dx = e.changedTouches[0].clientX - x0, dy = e.changedTouches[0].clientY - y0;
    if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) aller(dx > 0 ? 'prec' : 'suiv');
    x0 = null;
  }, { passive: true });
  // Précharge la photo suivante pour un affichage instantané
  var s = document.getElementById('suiv');
  if (s && document.querySelector('img#media')) {
    var u = new URL(s.href, location.href);
    u.searchParams.set('p', 'apercu');
    new Image().src = u.toString();
  }
})();
