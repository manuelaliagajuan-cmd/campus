document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.querySelector('.mobile-toggle');
  if (toggle) toggle.addEventListener('click', () => {
    const open = document.body.classList.toggle('menu-open');
    toggle.setAttribute('aria-expanded', String(open));
  });
  document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  }));

  document.querySelectorAll('.drag-list').forEach(list => {
    const status = document.createElement('p');
    status.className = 'sr-only';
    status.setAttribute('aria-live', 'polite');
    list.after(status);
    const refresh = () => {
      const items = [...list.querySelectorAll(':scope > [data-sortable-item]')];
      items.forEach((item, index) => {
        item.querySelector('.sort-position').textContent = index + 1;
        item.querySelector('[data-move="up"]').disabled = index === 0;
        item.querySelector('[data-move="down"]').disabled = index === items.length - 1;
      });
      return items;
    };
    const announce = item => {
      const items = refresh();
      status.textContent = `${item.querySelector('.text').textContent}: ${items.indexOf(item) + 1} / ${items.length}`;
    };
    list.addEventListener('click', event => {
      const button = event.target.closest('[data-move]');
      if (!button || button.disabled) return;
      const item = button.closest('[data-sortable-item]');
      if (button.dataset.move === 'up' && item.previousElementSibling) list.insertBefore(item, item.previousElementSibling);
      if (button.dataset.move === 'down' && item.nextElementSibling) list.insertBefore(item.nextElementSibling, item);
      announce(item);
      const focusButton = button.disabled ? item.querySelector('[data-move]:not(:disabled)') : button;
      focusButton?.focus();
    });
    refresh();
    if (typeof window.Sortable !== 'function') return;
    new window.Sortable(list, {
      draggable: '[data-sortable-item]',
      animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180,
      forceFallback: true,
      fallbackOnBody: true,
      fallbackTolerance: 4,
      ghostClass: 'sort-placeholder',
      chosenClass: 'sort-chosen',
      fallbackClass: 'sort-floating',
      filter: 'button, input, a, select, textarea',
      preventOnFilter: false,
      swapThreshold: 0.65,
      scroll: true,
      bubbleScroll: true,
      scrollSensitivity: 70,
      scrollSpeed: 12,
      onStart: () => { list.classList.add('sort-active'); document.body.classList.add('is-sorting'); },
      onChange: event => {
        refresh();
        const floatingPosition = document.querySelector('.sort-floating .sort-position');
        if (floatingPosition) floatingPosition.textContent = event.newIndex + 1;
      },
      onEnd: event => {
        list.classList.remove('sort-active');
        document.body.classList.remove('is-sorting');
        announce(event.item);
      }
    });
  });

  const notificationCount = document.querySelector('[data-notification-count]');
  if (notificationCount) {
    const poll = async () => {
      if (document.hidden) return;
      try {
        const response = await fetch(notificationCount.dataset.url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
        if (!response.ok) return;
        const {count} = await response.json();
        notificationCount.textContent = count > 99 ? '99+' : count;
        notificationCount.hidden = count === 0;
        notificationCount.closest('a').setAttribute('aria-label', window.cecT('Notificaciones') + ': ' + count + ' ' + window.cecT('sin leer'));
      } catch (_) { /* Existing notices remain available when the connection is interrupted. */ }
    };
    window.setInterval(poll, 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
  }
});
