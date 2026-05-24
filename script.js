const pushConfig = {
  vapidPublicKey:
    window.VAERVAKT_CONFIG?.vapidPublicKey ||
    document.querySelector('meta[name="vapid-public-key"]')?.content ||
    '',
  subscriptionEndpoint: window.VAERVAKT_CONFIG?.subscriptionEndpoint || '',
};

let pushRegistrationPromise = null;

function urlBase64ToUint8Array(base64String) {
  const normalized = String(base64String || '').trim().replace(/\s/g, '');
  if (!normalized) {
    throw new Error('Mangler VAPID public key');
  }

  const remainder = normalized.length % 4;
  if (remainder === 1) {
    throw new Error('Ugyldig VAPID public key-lengde');
  }

  const padding = '='.repeat((4 - remainder) % 4);
  const base64 = (normalized + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  return Uint8Array.from(rawData, (char) => char.charCodeAt(0));
}

function notify(message) {
  if (window.VaervaktApp?.showToast) {
    window.VaervaktApp.showToast(message);
  }
}

async function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) {
    notify('Service worker støttes ikke i denne nettleseren.');
    return null;
  }

  return navigator.serviceWorker.register('service-worker.js');
}

async function ensureNotificationPermission() {
  if (!('Notification' in window)) {
    return 'unsupported';
  }

  if (Notification.permission === 'granted') {
    return 'granted';
  }

  if (Notification.permission === 'denied') {
    return 'denied';
  }

  return Notification.requestPermission();
}

async function subscribeToPush() {
  if (pushRegistrationPromise) {
    return pushRegistrationPromise;
  }

  pushRegistrationPromise = (async () => {
    if (!('PushManager' in window)) {
      notify('Push-varsler støttes ikke i denne nettleseren.');
      return null;
    }

    const registration = await registerServiceWorker();
    if (!registration) return null;

    const permission = await ensureNotificationPermission();
    if (permission !== 'granted') {
      notify(permission === 'denied' ? 'Varsler er blokkert i nettleseren.' : 'Varsler ble ikke tillatt.');
      return null;
    }

    const existing = await registration.pushManager.getSubscription();
    if (existing) {
      notify('Push-varsler er allerede aktive.');
      return existing;
    }

    const applicationServerKey = urlBase64ToUint8Array(pushConfig.vapidPublicKey);
    const subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey,
    });

    await persistSubscription(subscription);
    notify('Push-varsler er aktivert.');
    return subscription;
  })().catch((error) => {
    console.error('Kunne ikke aktivere push-varsler:', error);
    notify('Kunne ikke aktivere push-varsler.');
    return null;
  }).finally(() => {
    pushRegistrationPromise = null;
  });

  return pushRegistrationPromise;
}

async function persistSubscription(subscription) {
  if (!pushConfig.subscriptionEndpoint) {
    localStorage.setItem('vaervakt_push_subscription', JSON.stringify(subscription));
    return;
  }

  await fetch(pushConfig.subscriptionEndpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ subscription }),
  });
}

function addPushButton() {
  const nav = document.querySelector('[data-nav-item="alerts"]');
  if (!nav) return;

  nav.addEventListener('click', async () => {
    if (!pushConfig.vapidPublicKey) {
      notify('Legg inn VAPID public key i meta-taggen først.');
      return;
    }

    await subscribeToPush();
  });
}

document.addEventListener('DOMContentLoaded', () => {
  registerServiceWorker();
  addPushButton();
});

window.VaervaktPush = {
  subscribeToPush,
  urlBase64ToUint8Array,
};
