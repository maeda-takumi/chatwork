window.addEventListener('DOMContentLoaded', () => {
  const cards = document.querySelectorAll('.card');

  cards.forEach((card) => {
    card.addEventListener('mousemove', (e) => {
      const rect = card.getBoundingClientRect();
      const x = (e.clientX - rect.left) / rect.width - 0.5;
      const y = (e.clientY - rect.top) / rect.height - 0.5;
      card.style.transform = `translateY(-3px) rotateX(${(-y * 2).toFixed(2)}deg) rotateY(${(x * 2).toFixed(2)}deg)`;
    });

    card.addEventListener('mouseleave', () => {
      card.style.transform = '';
    });
  });
  const dropdown = document.querySelector('[data-target-dropdown]');
  if (dropdown) {
    const toggle = dropdown.querySelector('[data-target-toggle]');
    const menu = dropdown.querySelector('[data-target-menu]');
    const input = document.querySelector('[data-target-input]');

    toggle?.addEventListener('click', () => {
      dropdown.classList.toggle('is-open');
    });

    menu?.querySelectorAll('.target-option').forEach((option) => {
      option.addEventListener('click', () => {
        if (!input) return;
        input.value = option.dataset.value ?? '';
        const img = option.querySelector('img')?.getAttribute('src') ?? 'img/all.png';
        const label = option.querySelector('span')?.textContent ?? '対象者を選択';
        const currentImg = toggle?.querySelector('img');
        const currentLabel = toggle?.querySelector('span');
        if (currentImg) currentImg.setAttribute('src', img);
        if (currentLabel) currentLabel.textContent = label;
        dropdown.classList.remove('is-open');
      });
    });

    document.addEventListener('click', (event) => {
      if (!dropdown.contains(event.target)) {
        dropdown.classList.remove('is-open');
      }
    });
  }

  document.querySelectorAll('.task-toggle').forEach((button) => {
    button.addEventListener('click', async () => {
      const card = button.closest('.message-card');
      if (!card || button.dataset.loading === '1') {
        return;
      }

      const messageId = Number(card.dataset.messageId || '0');
      if (!messageId) {
        return;
      }

      const currentDone = button.dataset.taskState === '1';
      const nextDone = !currentDone;
      button.dataset.loading = '1';

      card.classList.toggle('is-complete', nextDone);
      card.classList.toggle('stamp-animate', nextDone);
      button.textContent = nextDone ? '取消' : '完了';
      button.dataset.taskState = nextDone ? '1' : '0';

      try {
        const res = await fetch('task_toggle.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: messageId, task: nextDone })
        });

        if (!res.ok) {
          throw new Error('update failed');
        }
      } catch (error) {
        card.classList.toggle('is-complete', currentDone);
        card.classList.remove('stamp-animate');
        button.textContent = currentDone ? '取消' : '完了';
        button.dataset.taskState = currentDone ? '1' : '0';
        alert('更新に失敗しました。時間をおいて再試行してください。');
      } finally {
        button.dataset.loading = '0';
        if (nextDone) {
          setTimeout(() => card.classList.remove('stamp-animate'), 700);
        }
      }
    });
  });
});