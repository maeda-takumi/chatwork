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
  const searchForm = document.querySelector('[data-search-form]');
  const submitSearchForm = () => {
    if (!searchForm) {
      return;
    }
    searchForm.requestSubmit();
  };
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
        submitSearchForm();
      });
    });

    document.addEventListener('click', (event) => {
      if (!dropdown.contains(event.target)) {
        dropdown.classList.remove('is-open');
      }
    });
  }

  if (searchForm) {
    const textInput = searchForm.querySelector('[data-search-text-input]');
    let textInputTimer = null;
    textInput?.addEventListener('input', () => {
      if (textInputTimer) {
        clearTimeout(textInputTimer);
      }
      textInputTimer = window.setTimeout(() => {
        submitSearchForm();
      }, 350);
    });

    const typeHiddenContainer = searchForm.querySelector('[data-type-hidden-container]');
    const updateTypeHiddenInputs = () => {
      if (!typeHiddenContainer) {
        return;
      }
      typeHiddenContainer.innerHTML = '';
      searchForm.querySelectorAll('[data-type-toggle].is-active').forEach((button) => {
        const typeName = button.getAttribute('data-type-name') || '';
        if (!typeName) {
          return;
        }
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'type[]';
        hidden.value = typeName;
        typeHiddenContainer.appendChild(hidden);
      });
    };

    searchForm.querySelectorAll('[data-type-toggle]').forEach((button) => {
      button.addEventListener('click', () => {
        button.classList.toggle('is-active');
        updateTypeHiddenInputs();
        submitSearchForm();
      });
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
  const replyModal = document.querySelector('[data-reply-modal]');
  const replyMeta = replyModal?.querySelector('[data-reply-meta]');
  const replyBodyInput = replyModal?.querySelector('[data-reply-body]');
  const replySubmit = replyModal?.querySelector('[data-reply-submit]');
  const replyState = { roomId: '', toMessageId: '', aid: '', senderLabel: '' };

  const closeReplyModal = () => {
    if (!replyModal) return;
    replyModal.hidden = true;
    if (replyBodyInput) replyBodyInput.value = '';
  };

  document.querySelectorAll('[data-reply-open]').forEach((button) => {
    button.addEventListener('click', () => {
      if (!replyModal) return;
      replyState.roomId = button.getAttribute('data-room-id') || '';
      replyState.toMessageId = button.getAttribute('data-to-message-id') || '';
      replyState.aid = button.getAttribute('data-aid') || '';
      replyState.senderLabel = button.getAttribute('data-sender-label') || '対象ユーザー';
      if (replyMeta) {
        replyMeta.textContent = `返信先: ${replyState.senderLabel} / message_id: ${replyState.toMessageId}`;
      }
      replyModal.hidden = false;
      replyBodyInput?.focus();
    });
  });

  replyModal?.querySelectorAll('[data-reply-close]').forEach((button) => {
    button.addEventListener('click', closeReplyModal);
  });

  replySubmit?.addEventListener('click', async () => {
    const body = replyBodyInput?.value.trim() || '';
    if (!body) {
      alert('返信内容を入力してください。');
      return;
    }
    if (replySubmit.dataset.loading === '1') {
      return;
    }
    replySubmit.dataset.loading = '1';
    try {
      const form = new URLSearchParams();
      form.set('action', 'reply');
      form.set('room_id', replyState.roomId);
      form.set('to_message_id', replyState.toMessageId);
      form.set('aid', replyState.aid);
      form.set('body', body);
      const res = await fetch('list.php', { method: 'POST', body: form });
      const json = await res.json();
      if (!res.ok || !json.ok) {
        throw new Error(json.error || '返信に失敗しました。');
      }
      alert('返信を送信しました。');
      closeReplyModal();
    } catch (error) {
      alert(error.message || '返信に失敗しました。');
    } finally {
      replySubmit.dataset.loading = '0';
    }
  });
});