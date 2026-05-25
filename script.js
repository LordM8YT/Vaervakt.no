const pushConfig = {
  vapidPublicKey:
    window.VAERVAKT_CONFIG?.vapidPublicKey ||
    document.querySelector('meta[name="vapid-public-key"]')?.content ||
    '',
  subscriptionEndpoint: window.VAERVAKT_CONFIG?.subscriptionEndpoint || '',
};

let pushRegistrationPromise = null;
let pushConfigPromise = null;

async function loadPushConfig() {
  if (pushConfigPromise) {
    return pushConfigPromise;
  }

  pushConfigPromise = fetch('api/config.php', {
    headers: { Accept: 'application/json' },
    cache: 'no-store',
  }).then(async (response) => {
    if (!response.ok) return pushConfig;
    const payload = await response.json();
    if (payload.vapidPublicKey) {
      pushConfig.vapidPublicKey = payload.vapidPublicKey;
    }
    if (payload.subscriptionEndpoint) {
      pushConfig.subscriptionEndpoint = payload.subscriptionEndpoint;
    }
    return pushConfig;
  }).catch(() => pushConfig);

  return pushConfigPromise;
}

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

const SERVICE_WORKER_VERSION = '20260525-reports1';
const SERVICE_WORKER_UPDATE_INTERVAL_MS = 5 * 60 * 1000;
let serviceWorkerReloading = false;
let serviceWorkerReloadListenerBound = false;
let serviceWorkerUpdateTimer = null;

function reloadWhenServiceWorkerTakesControl() {
  if (!('serviceWorker' in navigator) || serviceWorkerReloadListenerBound) return;
  serviceWorkerReloadListenerBound = true;

  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (serviceWorkerReloading) return;
    serviceWorkerReloading = true;
    notify('Værvakt er oppdatert. Laster inn ny versjon…');
    window.setTimeout(() => window.location.reload(), 250);
  });
}

function startServiceWorkerUpdateChecks(registration) {
  if (!registration || serviceWorkerUpdateTimer) return;

  const checkForUpdate = () => registration.update?.().catch((error) => {
    console.warn('Kunne ikke sjekke service worker-oppdatering:', error);
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') checkForUpdate();
  });

  serviceWorkerUpdateTimer = window.setInterval(checkForUpdate, SERVICE_WORKER_UPDATE_INTERVAL_MS);
}

async function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) {
    notify('Service worker støttes ikke i denne nettleseren.');
    return null;
  }

  reloadWhenServiceWorkerTakesControl();

  const registration = await navigator.serviceWorker.register(`service-worker.js?v=${SERVICE_WORKER_VERSION}`, {
    updateViaCache: 'none',
  });
  await registration.update?.();
  startServiceWorkerUpdateChecks(registration);
  return registration;
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
    await loadPushConfig();

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
  const buttons = [document.querySelector('#push-action-button')].filter(Boolean);

  buttons.forEach((button) => button.addEventListener('click', async () => {
    await loadPushConfig();

    if (!pushConfig.vapidPublicKey) {
      notify('Legg inn VAPID public key i .env først.');
      return;
    }

    await subscribeToPush();
  }));
}

document.addEventListener('DOMContentLoaded', () => {
  registerServiceWorker();
  loadPushConfig();
  addPushButton();
});

window.VaervaktPush = {
  subscribeToPush,
  urlBase64ToUint8Array,
};
