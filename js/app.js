document.addEventListener('DOMContentLoaded', () => {
  const searchInput = document.querySelector('.search-input');
  if (searchInput) {
    searchInput.addEventListener('focus', () => {
      document.body.classList.add('is-search-focus');
    });

    searchInput.addEventListener('blur', () => {
      document.body.classList.remove('is-search-focus');
    });
  }
});