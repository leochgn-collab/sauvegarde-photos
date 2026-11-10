// Bouton de thème : automatique → clair → sombre → automatique…
// Le choix est gardé dans un cookie (lu par le serveur : pas de flash au chargement).
(function () {
  var b = document.getElementById('bouton-theme');
  if (!b) return;
  var noms = { auto: 'automatique', clair: 'clair', sombre: 'sombre' };
  var suivant = { auto: 'clair', clair: 'sombre', sombre: 'auto' };
  b.addEventListener('click', function () {
    var html = document.documentElement;
    var t = suivant[html.getAttribute('data-theme')] || 'auto';
    html.setAttribute('data-theme', t);
    document.cookie = 'sp_theme=' + t + '; path=/; max-age=31536000; SameSite=Strict' + (location.protocol === 'https:' ? '; Secure' : '');
    var s = b.querySelector('span.long');
    if (s) s.textContent = 'Thème : ' + noms[t];
  });
})();
