/**
 * reLoopin Loyalty — Floating Launcher Widget (v2)
 *
 * Vanilla JS (no jQuery). Handles:
 *  - Logged-in / guest state toggle
 *  - Panel open/close with animations
 *  - Lazy data fetch on first open (balance, rules, history)
 *  - Tabbed navigation: Earn, Redeem, History
 *  - History pagination and filters
 *  - Birthday & referral modals
 *  - Copy-to-clipboard for referral URL
 *  - Toast notifications
 */
(function () {
  'use strict';

  /* global reloopinLauncher */
  if (typeof reloopinLauncher === 'undefined') return;

  var theme   = reloopinLauncher.theme || {};
  var primary = theme.primary || '#6054D0';
  var accent  = theme.accent  || '#A855F7';

  // ── i18n helper ─────────────────────────────────────────────────────────
  var i18n = reloopinLauncher.i18n || {};
  function t(key, replacements) {
    var str = i18n[key] || key;
    if (replacements) {
      for (var i = 0; i < replacements.length; i++) {
        str = str.replace('%s', replacements[i]);
      }
    }
    return str;
  }

  // ── State ──────────────────────────────────────────────────────────────
  var panelOpen    = false;
  var guestOpen    = false;
  var dataLoaded   = false;
  var rulesLoaded  = false;
  var histLoaded   = false;
  var historyPage  = 1;
  var historyFilter = '';
  var toastTimer   = null;
  var userData     = null;
  var earnStatus       = null;   // { completed: string[], birthday_set: bool }
  var earnStatusLoaded = false;
  var currentRules     = [];     // module-level so birthday save can re-render
  var campaignsLoaded  = false;
  var campaignsData    = [];
  var couponsLoaded    = false;
  var couponsData      = [];
  var guestRulesLoaded     = false;
  var guestCampaignsLoaded = false;

  // ── DOM refs ───────────────────────────────────────────────────────────
  var root = document.getElementById('rl-root');
  if (!root) return;

  var elLoggedin     = document.getElementById('rl-loggedin');
  var elGuest        = document.getElementById('rl-guest');
  var launcher       = document.getElementById('rl-launcher');
  var launcherGuest  = document.getElementById('rl-launcher-guest');
  var panel          = document.getElementById('rl-panel');
  var panelGuest     = document.getElementById('rl-panel-guest');
  var hint           = document.getElementById('rl-hint');
  var guestEarnBody  = document.getElementById('rl-guest-earn-body');
  var guestRedeemBody = document.getElementById('rl-guest-redeem-body');

  // ── Signed-in launcher points (compact on small screens) ────────────────
  // Defined before init so preloaded balance can call updateLauncherPts().
  var compactPtsMq = window.matchMedia('(max-width: 420px)');
  var lastLauncherPts = null;

  function formatCompactPts(n) {
    if (n >= 1000000) {
      var m = n / 1000000;
      return (m >= 10 ? Math.round(m) : Math.round(m * 10) / 10) + 'M';
    }
    if (n >= 1000) {
      var k = n / 1000;
      return (k >= 10 ? Math.round(k) : Math.round(k * 10) / 10) + 'K';
    }
    return String(n);
  }

  function formatLauncherPtsLabel(pts) {
    if (compactPtsMq.matches) {
      return formatCompactPts(pts);
    }
    return pts.toLocaleString() + ' pts';
  }

  function updateLauncherPts(pts, isError) {
    var launcherPts = document.getElementById('rl-launcher-pts');
    var launcherBtn = document.getElementById('rl-launcher');
    if (isError) {
      lastLauncherPts = null;
      if (launcherPts) launcherPts.textContent = compactPtsMq.matches ? '--' : '-- pts';
      if (launcherBtn) launcherBtn.setAttribute('aria-label', t('launcher_aria', ['--']));
      return;
    }
    lastLauncherPts = pts;
    if (launcherPts) launcherPts.textContent = formatLauncherPtsLabel(pts);
    if (launcherBtn) launcherBtn.setAttribute('aria-label', t('launcher_aria', [pts.toLocaleString()]));
  }

  function onCompactPtsMqChange() {
    if (lastLauncherPts !== null) {
      updateLauncherPts(lastLauncherPts);
    }
  }
  if (compactPtsMq.addEventListener) {
    compactPtsMq.addEventListener('change', onCompactPtsMqChange);
  } else if (compactPtsMq.addListener) {
    compactPtsMq.addListener(onCompactPtsMqChange);
  }

  // ── Live session (source of truth) ─────────────────────────────────────
  var SESSION_TICK_KEY = 'reloopin_session_tick';
  var SYNC_COOLDOWN_MS = 2000;

  function readAuthCookie() {
    var match = document.cookie.match(/(?:^|; )reloopin_auth=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
  }

  function writeAuthCookie(loggedIn) {
    try {
      var secure = location.protocol === 'https:' ? '; Secure' : '';
      var maxAge = loggedIn ? 31536000 : 0;
      document.cookie = 'reloopin_auth=' + (loggedIn ? '1' : '0') + '; path=/; max-age=' + maxAge + '; SameSite=Lax' + secure;
    } catch (e) { /* private mode */ }
  }

  var authCookie = readAuthCookie();
  var hintLoggedIn = authCookie === '1' || (authCookie !== '0' && !!reloopinLauncher.is_logged_in);

  var session = {
    ready: false,
    loggedIn: hintLoggedIn,
    userId: hintLoggedIn ? Number(reloopinLauncher.user_id || 0) || null : null,
    name: reloopinLauncher.user_first_name || '',
    initials: reloopinLauncher.user_initials || '',
    nonce: reloopinLauncher.nonce || ''
  };
  var pendingToggle = null;
  var applyingFromStorage = false;
  var syncInFlight = null;
  var lastSyncAt = 0;
  var syncTimer = null;
  var sessionReady = null;

  function resetLazyFlags() {
    dataLoaded = false;
    rulesLoaded = false;
    histLoaded = false;
    campaignsLoaded = false;
    couponsLoaded = false;
    earnStatusLoaded = false;
    guestRulesLoaded = false;
    guestCampaignsLoaded = false;
    userData = null;
    earnStatus = null;
    currentRules = [];
    campaignsData = [];
    couponsData = [];
  }

  function applySessionIdentity() {
    var avEls = [document.getElementById('rl-user-av'), document.getElementById('rl-launcher-av')];
    avEls.forEach(function (el) {
      if (el) el.textContent = session.initials || '';
    });
    var nameEl = document.getElementById('rl-user-name');
    if (nameEl) nameEl.textContent = t('welcome_back', [session.name || '']);
  }

  function maybeApplyPreloaded() {
    var pre = reloopinLauncher.preloaded_data;
    if (!pre || !pre.logged_in || !session.loggedIn) return;
    var preUser = Number(pre.user_id || reloopinLauncher.user_id || 0);
    if (preUser && session.userId && preUser !== session.userId) return;
    applyUserData(pre);
    dataLoaded = true;
  }

  function showLoggedIn() {
    if (elLoggedin) elLoggedin.style.display = '';
    if (elGuest) elGuest.style.display = 'none';
    if (panelGuest) panelGuest.classList.remove('open');
    guestOpen = false;
    applySessionIdentity();
  }

  function showGuest() {
    if (elGuest) elGuest.style.display = '';
    if (elLoggedin) elLoggedin.style.display = 'none';
    if (panel) panel.classList.remove('open');
    panelOpen = false;
  }

  function broadcastSessionTick() {
    if (applyingFromStorage) return;
    try {
      localStorage.setItem(SESSION_TICK_KEY, String(Date.now()));
    } catch (e) { /* private mode */ }
  }

  function applySession(payload) {
    payload = payload || {};
    var wasReady = session.ready;
    var wasLoggedIn = session.loggedIn;
    var prevUserId = session.userId;
    var guestWasOpen = guestOpen;

    var nextLoggedIn = !!payload.logged_in;
    var nextUserId = nextLoggedIn ? (Number(payload.user_id || 0) || null) : null;
    var identityChanged = wasReady && (wasLoggedIn !== nextLoggedIn || prevUserId !== nextUserId);

    if (payload.nonce) {
      session.nonce = payload.nonce;
      reloopinLauncher.nonce = payload.nonce;
    }

    session.ready = true;
    session.loggedIn = nextLoggedIn;
    session.userId = nextUserId;
    if (payload.name) session.name = payload.name;
    if (payload.initials) session.initials = payload.initials;

    if (identityChanged) {
      resetLazyFlags();
    }

    if (session.loggedIn) {
      showLoggedIn();
      if (!dataLoaded) maybeApplyPreloaded();
    } else {
      showGuest();
    }
    writeAuthCookie(session.loggedIn);

    var upgradedWithGuestOpen = !wasLoggedIn && session.loggedIn && guestWasOpen;
    if (upgradedWithGuestOpen) {
      pendingToggle = null;
      if (!panelOpen) togglePanel();
    } else if (pendingToggle) {
      var queued = pendingToggle;
      pendingToggle = null;
      requestToggle(queued);
    }

    if (identityChanged) {
      broadcastSessionTick();
    }
  }

  function syncSession(force) {
    if (!force && session.ready && lastSyncAt && (Date.now() - lastSyncAt) < SYNC_COOLDOWN_MS) {
      return sessionReady || Promise.resolve(session);
    }
    if (syncInFlight) return syncInFlight;

    syncInFlight = new Promise(function (resolve) {
      ajaxPost('reloopin_launcher_session', null, function (data) {
        lastSyncAt = Date.now();
        applySession(data || { logged_in: false, user_id: 0 });
        syncInFlight = null;
        resolve(session);
      }, function () {
        if (!session.ready) {
          applySession({
            logged_in: hintLoggedIn,
            nonce: reloopinLauncher.nonce,
            user_id: hintLoggedIn ? Number(reloopinLauncher.user_id || 0) : 0,
            name: reloopinLauncher.user_first_name || '',
            initials: reloopinLauncher.user_initials || ''
          });
        }
        syncInFlight = null;
        resolve(session);
      }, { skipSessionWait: true });
    });
    return syncInFlight;
  }

  function scheduleSyncSession() {
    if (syncTimer) clearTimeout(syncTimer);
    syncTimer = setTimeout(function () {
      syncSession(false);
    }, 300);
  }

  function requestToggle(kind) {
    if (!session.ready) {
      pendingToggle = kind;
      return;
    }
    if (session.loggedIn) {
      togglePanel();
    } else {
      toggleGuestPanel();
    }
  }

  // First paint from hints only — session AJAX overwrites this.
  if (hintLoggedIn) {
    showLoggedIn();
    maybeApplyPreloaded();
  } else {
    showGuest();
  }

  // ── Panel toggle — logged in ───────────────────────────────────────────
  function togglePanel() {
    panelOpen = !panelOpen;
    if (panelOpen) {
      panel.classList.add('open');
      if (hint) hint.style.display = 'none';
      if (!dataLoaded) {
        fetchData();
      } else if (!rulesLoaded) {
        fetchRules();
      }
    } else {
      panel.classList.remove('open');
    }
  }

  // ── Panel toggle — guest ───────────────────────────────────────────────
  function toggleGuestPanel() {
    guestOpen = !guestOpen;
    if (guestOpen) {
      panelGuest.classList.add('open');
      if (!guestRulesLoaded) fetchGuestRules();
      if (!guestCampaignsLoaded) fetchGuestCampaigns();
    } else {
      panelGuest.classList.remove('open');
    }
  }

  root.addEventListener('click', function (e) {
    if (e.target.closest('#rl-launcher')) {
      e.stopPropagation();
      requestToggle('loggedin');
      return;
    }
    if (e.target.closest('#rl-launcher-guest')) {
      e.stopPropagation();
      requestToggle('guest');
      return;
    }
    if (e.target.closest('#rl-close-btn')) {
      e.stopPropagation();
      if (panelOpen) {
        panelOpen = true;
        togglePanel();
      }
      return;
    }
    if (e.target.closest('#rl-guest-close-btn') || e.target.closest('.rl-guest-hero-close')) {
      e.stopPropagation();
      if (guestOpen) {
        guestOpen = true;
        toggleGuestPanel();
      }
    }
  });

  // ── Outside click & Escape ─────────────────────────────────────────────
  document.addEventListener('click', function (e) {
    if (panelOpen && panel && !panel.contains(e.target) && launcher && !launcher.contains(e.target)) {
      panelOpen = true;
      togglePanel();
    }
    if (guestOpen && panelGuest && !panelGuest.contains(e.target) && launcherGuest && !launcherGuest.contains(e.target)) {
      guestOpen = true;
      toggleGuestPanel();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' || e.keyCode === 27) {
      if (panelOpen) { panelOpen = true; togglePanel(); }
      if (guestOpen) { guestOpen = true; toggleGuestPanel(); }
      closeAllModals();
    }
  });

  window.addEventListener('pageshow', function (e) {
    if (e.persisted || (performance.getEntriesByType && performance.getEntriesByType('navigation')[0] && performance.getEntriesByType('navigation')[0].type === 'back_forward')) {
      syncSession(true);
    }
  });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') scheduleSyncSession();
  });

  window.addEventListener('focus', function () {
    scheduleSyncSession();
  });

  window.addEventListener('storage', function (e) {
    if (e.key !== SESSION_TICK_KEY) return;
    applyingFromStorage = true;
    syncSession(true).then(function () {
      applyingFromStorage = false;
    }, function () {
      applyingFromStorage = false;
    });
  });

  // ── Tabs ───────────────────────────────────────────────────────────────
  var tabs = root.querySelectorAll('.rl-tab');
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      var name = tab.getAttribute('data-tab');
      tabs.forEach(function (t) { t.classList.remove('active'); });
      tab.classList.add('active');
      root.querySelectorAll('#rl-panel .rl-pane').forEach(function (p) { p.classList.remove('active'); });
      var pane = document.getElementById('rl-' + name);
      if (pane) pane.classList.add('active');

      // Lazy-load on first view
      if (name === 'earn' && !rulesLoaded) fetchRules();
      if (name === 'redeem' && !campaignsLoaded) fetchCampaigns();
      if (name === 'history' && !histLoaded) fetchHistory(1);
    });
  });

  // ── AJAX helper ────────────────────────────────────────────────────────
  function isNonceFailure(xhr, errData) {
    if (xhr.status === 403 || xhr.status === 400) return true;
    var text = (xhr.responseText || '').replace(/^\s+|\s+$/g, '');
    if (text === '-1' || text === '0') return true;
    if (errData && (errData === '-1' || errData.message === 'invalid_nonce')) return true;
    return false;
  }

  function ajaxPost(action, extraData, onSuccess, onError, options) {
    var opts = options || {};

    function send() {
      var data = new FormData();
      data.append('action', action);
      data.append('nonce', session.nonce || reloopinLauncher.nonce);
      if (extraData) {
        Object.keys(extraData).forEach(function (k) {
          data.append(k, extraData[k]);
        });
      }
      var xhr = new XMLHttpRequest();
      xhr.open('POST', reloopinLauncher.ajax_url, true);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

      function retryOrFail(maybeNonce, errData) {
        if (maybeNonce && !opts.retried && action !== 'reloopin_launcher_session') {
          syncSession(true).then(function () {
            ajaxPost(action, extraData, onSuccess, onError, { retried: true, skipSessionWait: true });
          });
          return;
        }
        if (onError) onError(errData);
      }

      xhr.onload = function () {
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            var resp = JSON.parse(xhr.responseText);
            if (resp.success) {
              if (resp.data && resp.data.nonce) {
                session.nonce = resp.data.nonce;
                reloopinLauncher.nonce = resp.data.nonce;
              }
              onSuccess(resp.data);
            } else {
              retryOrFail(isNonceFailure(xhr, resp.data), resp.data);
            }
          } catch (e) {
            retryOrFail(isNonceFailure(xhr, null), null);
          }
        } else {
          retryOrFail(isNonceFailure(xhr, null), null);
        }
      };
      xhr.onerror = function () { if (onError) onError(null); };
      xhr.send(data);
    }

    if (opts.skipSessionWait || action === 'reloopin_launcher_session') {
      send();
    } else if (sessionReady) {
      sessionReady.then(send, send);
    } else {
      send();
    }
  }

  sessionReady = syncSession(true);

  // ── Apply user/balance data to UI ───────────────────────────────────────
  function applyUserData(data) {
    userData = data;

    var nameEl = document.getElementById('rl-user-name');
    if (nameEl) nameEl.textContent = t('welcome_back', [data.name || '']);

    var avEls = [document.getElementById('rl-user-av'), document.getElementById('rl-launcher-av')];
    avEls.forEach(function (el) {
      if (el) el.textContent = data.initials || '';
    });

    var pts = Number(data.available_points || 0);
    var ptsNum = document.getElementById('rl-pts-num');
    if (ptsNum) ptsNum.textContent = pts.toLocaleString();

    updateLauncherPts(pts);

    var hintPts = root.querySelector('.rl-hint-pts');
    if (hintPts) hintPts.textContent = pts.toLocaleString() + ' pts';

    var hsEarned = document.getElementById('rl-hs-earned');
    if (hsEarned) hsEarned.textContent = '+' + Number(data.lifetime_points || 0).toLocaleString();
    var hsRedeemed = document.getElementById('rl-hs-redeemed');
    if (hsRedeemed) hsRedeemed.textContent = '-' + Number(data.redeemed_points || 0).toLocaleString();
    var hsBalance = document.getElementById('rl-hs-balance');
    if (hsBalance) hsBalance.textContent = pts.toLocaleString();

    if (data.tier) {
      var tierBadge = document.getElementById('rl-tier-badge');
      var tierName = document.getElementById('rl-tier-name');
      if (tierBadge && tierName) {
        tierName.textContent = capitalize(data.tier);
        tierBadge.style.display = '';
      }
    }

    var refText = document.getElementById('rl-ref-link-text');
    if (refText && data.referral_url) refText.textContent = data.referral_url;
  }

  // ── Fetch balance data ─────────────────────────────────────────────────
  function fetchData() {
    dataLoaded = true; // prevent double-fire on rapid clicks
    ajaxPost('reloopin_launcher_data', null, function (data) {
      if (!data || !data.logged_in) {
        dataLoaded = false;
        if (data) applySession(data);
        return;
      }

      applyUserData(data);

      // Load rules
      if (!rulesLoaded) fetchRules();
    }, function (errData) {
      dataLoaded = false;
      // Show error indicator instead of leaving stale "..."
      var ptsNum = document.getElementById('rl-pts-num');
      if (ptsNum) ptsNum.textContent = '--';
      updateLauncherPts(null, true);
      console.warn('[reLoopin] Balance fetch failed', errData);
      // Still load rules so the Earn tab works even if balance fails
      if (!rulesLoaded) fetchRules();
    });
  }

  // TODO(next-tier UI): the tiers fetch was removed because the balance endpoint already returns the
  // current tier (shown via #rl-tier-badge in applyUserData). The progress-to-next-tier bar
  // (#rl-prog-wrap / #rl-tier-row) needs per-tier min_points thresholds, which the balance response
  // does not include. Re-add the computation here once the backend exposes next_tier + points_to_next
  // (the at_pts / pts_to i18n strings are kept for that).

  // ── Fetch rules ────────────────────────────────────────────────────────
  var ONE_TIME_EVENTS = ['signup', 'first_order', 'birthday'];

  var EVENT_TYPE_CONFIG = {
    product_purchase:          { icon: 'cart',     color: 'grn',  label: 'Purchase rewards' },
    featured_product_purchase: { icon: 'cart',     color: 'grn',  label: 'Featured product bonus' },
    signup:                    { icon: 'pin',      color: 'amb',  label: 'Sign up bonus' },
    first_order:               { icon: 'cart',     color: 'grn',  label: 'First order bonus' },
    birthday:                  { icon: 'calendar', color: 'fuch', label: 'Birthday bonus' },
    referral:                  { icon: 'users',    color: 'pur',  label: 'Refer a friend' },
    campaign_coupons:          { icon: 'tag',      color: 'amb',  label: 'Campaign bonus' },
    free_shipping:             { icon: 'truck',    color: 'grn',  label: 'Free shipping bonus' },
    other:                     { icon: 'star',     color: 'pur',  label: 'Bonus' }
  };

  var SVG_ICONS = {
    cart: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
    pin: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
    calendar: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    users: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    tag: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
    truck: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
    star: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
    heart: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
    lock: '<svg width="7" height="7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
    check: '<svg width="7" height="7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
  };

  var STROKE_COLORS = {
    grn: '#059669',
    pur: primary,
    amb: '#D97706',
    fuch: accent
  };

  function fetchEarnStatus(onDone) {
    if (!session.loggedIn) {
      earnStatus = { completed: [], birthday_set: false };
      earnStatusLoaded = true;
      if (onDone) onDone();
      return;
    }
    ajaxPost('reloopin_launcher_earn_status', null, function (data) {
      earnStatus = data || { completed: [], birthday_set: false };
      earnStatusLoaded = true;
      if (onDone) onDone();
    }, function () {
      earnStatus = { completed: [], birthday_set: false };
      earnStatusLoaded = true;
      if (onDone) onDone();
    });
  }

  function fetchRules() {
    var earnLoading = document.getElementById('rl-earn-loading');
    var earnContent = document.getElementById('rl-earn-content');

    var rulesResult = null;
    var rulesDone   = false;
    var statusDone  = false;

    function tryRender() {
      if (!rulesDone || !statusDone) return;

      rulesLoaded  = true;
      currentRules = rulesResult || [];

      renderEarnTab(currentRules);

      if (earnLoading) earnLoading.style.display = 'none';
      if (earnContent) earnContent.style.display = '';

      // Badge shows only "Ready to earn" rules
      var badge = document.getElementById('rl-earn-badge');
      if (badge) {
        var completed = (earnStatus && earnStatus.completed) || [];
        var readyCount = currentRules.filter(function (r) {
          var isOneTime = ONE_TIME_EVENTS.indexOf(r.event_type) !== -1;
          return !(isOneTime && completed.indexOf(r.event_type) !== -1);
        }).length;
        badge.textContent = readyCount;
      }
    }

    // Fire both fetches in parallel
    ajaxPost('reloopin_launcher_rules', null, function (rules) {
      rulesResult = rules || [];
      rulesDone   = true;
      tryRender();
    }, function () {
      rulesLoaded = true;
      if (earnLoading) earnLoading.style.display = 'none';
      if (earnContent) {
        earnContent.innerHTML = '<p style="text-align:center;color:#9B96B0;font-size:.75rem;padding:1rem 0">' + esc(t('earn_error')) + '</p>';
        earnContent.style.display = '';
      }
    });

    fetchEarnStatus(function () {
      statusDone = true;
      tryRender();
    });
  }

  function renderEarnTab(rules) {
    var container = document.getElementById('rl-earn-content');
    if (!container) return;

    var completed   = (earnStatus && earnStatus.completed) || [];
    var alreadyDone = [];
    var readyToEarn = [];

    rules.forEach(function (rule) {
      var isOneTime = ONE_TIME_EVENTS.indexOf(rule.event_type) !== -1;
      if (isOneTime && completed.indexOf(rule.event_type) !== -1) {
        alreadyDone.push(rule);
      } else {
        readyToEarn.push(rule);
      }
    });

    var html = '';

    // ── Group 1: Already earned ────────────────────────────────────────
    if (alreadyDone.length > 0) {
      html += '<div class="rl-group"><span>' + esc(t('already_earned')) + '</span><span class="rl-group-line"></span></div>';
      alreadyDone.forEach(function (rule) {
        var cfg     = EVENT_TYPE_CONFIG[rule.event_type] || EVENT_TYPE_CONFIG.other;
        var iconSvg = (SVG_ICONS[cfg.icon] || SVG_ICONS.star).replace(/stroke="currentColor"/g, 'stroke="#A8A29E"');

        var ptsDisplay = rule.rule_type === 'multiplier'
          ? 'x' + Number(rule.earn_rate || 0).toLocaleString() + ' pts'
          : Number(rule.earn_rate || 0).toLocaleString() + ' pts';

        var subtitle = rule.description || (rule.event_type === 'birthday'
          ? t('annual_bonus')
          : t('one_time_bonus'));

        html += '<div class="rl-earn used">' +
          '<div class="rl-icon ri-' + esc(cfg.color) + ' rl-icon-muted">' + iconSvg + '</div>' +
          '<div class="rl-earn-body">' +
            '<div class="rl-earn-title" style="color:#A8A29E">' + esc(rule.name || cfg.label) + '</div>' +
            '<div class="rl-earn-sub">' + esc(subtitle) + '</div>' +
          '</div>' +
          '<div class="rl-earn-r">' +
            '<div class="rl-earn-pts muted">' + esc(ptsDisplay) + '</div>' +
            '<div class="rl-pill rp-done">' + SVG_ICONS.check + ' ' + esc(t('collected')) + '</div>' +
          '</div>' +
        '</div>';
      });
    }

    // ── Group 2: Ready to earn ─────────────────────────────────────────
    if (readyToEarn.length > 0) {
      html += '<div class="rl-group"' + (alreadyDone.length > 0 ? ' style="margin-top:.85rem"' : '') + '><span>' + esc(t('ready_to_earn')) + '</span><span class="rl-group-line"></span></div>';
      readyToEarn.forEach(function (rule) {
        var cfg         = EVENT_TYPE_CONFIG[rule.event_type] || EVENT_TYPE_CONFIG.other;
        var strokeColor = STROKE_COLORS[cfg.color] || primary;
        var iconSvg     = (SVG_ICONS[cfg.icon] || SVG_ICONS.star).replace(/stroke="currentColor"/g, 'stroke="' + strokeColor + '"');
        var clickAttr   = '';
        if (rule.event_type === 'birthday') clickAttr = ' style="cursor:pointer" data-action="birthday"';
        if (rule.event_type === 'referral') clickAttr = ' style="cursor:pointer" data-action="referral"';

        var subtitle = rule.description || (rule.rule_type === 'multiplier'
          ? t('pts_per_dollar', [rule.earn_rate])
          : t('one_time_bonus'));

        var ptsDisplay = rule.rule_type === 'multiplier'
          ? 'x' + Number(rule.earn_rate || 0).toLocaleString() + ' pts'
          : Number(rule.earn_rate || 0).toLocaleString() + ' pts';

        var pillLabel = rule.event_type === 'birthday' ? esc(t('add_now'))
          : rule.event_type === 'referral' ? esc(t('get_link'))
          : esc(t('available'));

        html += '<div class="rl-earn avail"' + clickAttr + '>' +
          '<div class="rl-icon ri-' + esc(cfg.color) + '">' + iconSvg + '</div>' +
          '<div class="rl-earn-body">' +
            '<div class="rl-earn-title">' + esc(rule.name || cfg.label) + '</div>' +
            '<div class="rl-earn-sub">' + esc(subtitle) + '</div>' +
          '</div>' +
          '<div class="rl-earn-r">' +
            '<div class="rl-earn-pts earn">' + esc(ptsDisplay) + '</div>' +
            '<div class="rl-pill rp-avail">' + pillLabel + '</div>' +
          '</div>' +
        '</div>';
      });
    }

    if (!html) {
      html = '<p style="text-align:center;color:#9B96B0;font-size:.75rem;padding:1rem 0">' + esc(t('no_earn_rules')) + '</p>';
    }

    container.innerHTML = html;

    // Attach click handlers
    container.querySelectorAll('[data-action="birthday"]').forEach(function (el) {
      el.addEventListener('click', function () { openModal('rl-bday-modal'); });
    });
    container.querySelectorAll('[data-action="referral"]').forEach(function (el) {
      el.addEventListener('click', function () { openModal('rl-ref-modal'); });
    });
  }

  // ── Campaigns (Redeem tab) ─────────────────────────────────────────────

  function fetchCampaigns() {
    var loading = document.getElementById('rl-redeem-loading');
    var content = document.getElementById('rl-redeem-content');
    if (loading) loading.style.display = '';
    if (content) content.style.display = 'none';

    ajaxPost('reloopin_launcher_campaigns', null, function (data) {
      campaignsLoaded = true;
      campaignsData   = data || [];
      if (loading) loading.style.display = 'none';
      if (content) content.style.display = '';
      renderRedeemTab(campaignsData);
    }, function () {
      campaignsLoaded = true;
      if (loading) loading.style.display = 'none';
      if (content) {
        content.innerHTML = '<p style="text-align:center;color:#9B96B0;font-size:.75rem;padding:1rem 0">'
          + esc(t('campaigns_error')) + '</p>';
        content.style.display = '';
      }
    });
  }

  function renderRedeemTab(campaigns) {
    var container = document.getElementById('rl-redeem-content');
    if (!container) return;

    // Coupons section placeholder (rendered by fetchCustomerCoupons)
    var html = '<div id="rl-coupons-section"></div>';

    var pts  = userData ? (userData.available_points || 0) : 0;
    html += '<div class="rl-group"><span>'
      + esc(t('redeem_your_points', [pts.toLocaleString()]))
      + '</span><span class="rl-group-line"></span></div>';

    if (!campaigns || campaigns.length === 0) {
      html += '<p style="text-align:center;color:#9B96B0;font-size:.75rem;padding:1.5rem 0;line-height:1.6">'
        + esc(t('no_campaigns')) + '</p>';
      container.innerHTML = html;
      if (!couponsLoaded) fetchCustomerCoupons();
      return;
    }

    campaigns.forEach(function (camp) {
      var canAfford  = pts >= camp.points_cost;
      var stateClass = canAfford ? 'can' : 'cant';
      var discount   = formatDiscount(camp.discount_type, camp.discount_value);
      var rightHtml;

      if (canAfford) {
        rightHtml = '<div class="rl-redeem-cost">\u2212' + esc(String(Number(camp.points_cost).toLocaleString())) + ' pts</div>'
          + '<div class="rl-redeem-val">' + esc(discount) + '</div>';
      } else {
        var needed = camp.points_cost - pts;
        rightHtml = '<div class="rl-redeem-cost" style="color:#9B96B0">' + esc(String(Number(camp.points_cost).toLocaleString())) + ' pts</div>'
          + '<div class="rl-redeem-need">' + esc(String(Number(needed).toLocaleString())) + ' ' + esc(t('pts_required')) + '</div>';
      }

      html += '<div class="rl-redeem ' + stateClass + '" data-campaign-id="' + esc(String(camp.id)) + '">'
        + '<div class="rl-icon ri-fuch"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="' + accent + '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg></div>'
        + '<div class="rl-redeem-body">'
        + '<div class="rl-redeem-title">' + esc(camp.name) + '</div>'
        + (camp.description ? '<div class="rl-redeem-sub">' + esc(camp.description) + '</div>' : '')
        + '</div>'
        + '<div class="rl-redeem-r">' + rightHtml + '</div>'
        + '</div>';
    });

    container.innerHTML = html;

    container.querySelectorAll('.rl-redeem.can').forEach(function (card) {
      card.addEventListener('click', function () {
        var id = parseInt(card.getAttribute('data-campaign-id'), 10);
        if (id) handleCouponGenerate(id, card);
      });
    });

    // Fetch coupons after campaigns are rendered
    if (!couponsLoaded) fetchCustomerCoupons();
  }

  // ── Customer Coupons (Redeem tab) ───────────────────────────────────────

  function fetchCustomerCoupons() {
    if (!session.loggedIn) return;
    couponsLoaded = true; // prevent double-fire

    ajaxPost('reloopin_launcher_coupons', null, function (data) {
      couponsData = data || [];
      renderCouponsSection(couponsData);
    }, function () {
      couponsLoaded = false; // allow retry on next tab visit
      var section = document.getElementById('rl-coupons-section');
      if (section) {
        section.innerHTML = '<p style="text-align:center;color:#9B96B0;font-size:.75rem;padding:.5rem 0">'
          + esc(t('coupons_error')) + '</p>';
      }
    });
  }

  function renderCouponsSection(coupons) {
    var section = document.getElementById('rl-coupons-section');
    if (!section) return;

    if (!coupons || coupons.length === 0) {
      section.innerHTML = '<p style="text-align:center;color:#9B96B0;font-size:.72rem;padding:.75rem 0;line-height:1.5">'
        + esc(t('no_coupons')) + '</p>';
      return;
    }

    var html = '<div class="rl-group"><span>'
      + esc(t('your_coupons'))
      + '</span><span class="rl-group-line"></span></div>';

    coupons.forEach(function (coupon) {
      var discount = formatDiscount(coupon.discount_type, coupon.discount_value);
      var metaParts = [];

      if (coupon.campaign_name) {
        metaParts.push(esc(coupon.campaign_name));
      }
      if (coupon.min_order_amount > 0) {
        metaParts.push(esc(t('min_order', [Number(coupon.min_order_amount).toFixed(2)])));
      }
      if (coupon.expires_at) {
        var d = new Date(coupon.expires_at);
        if (!isNaN(d.getTime())) {
          metaParts.push(esc(t('discount_expires')) + ' ' + esc(d.toLocaleDateString()));
        }
      }

      html += '<div class="rl-coupon-card">'
        + '<div class="rl-coupon-reveal">'
        + '<div class="rl-coupon-label">' + esc(discount) + '</div>'
        + '<div class="rl-coupon-box">'
        + '<span class="rl-coupon-code">' + esc(coupon.code) + '</span>'
        + '<button type="button" class="rl-coupon-copy-btn" data-code="' + esc(coupon.code) + '">'
        + '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="' + primary + '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>'
        + '<span class="rl-coupon-copy-label">' + esc(t('copy_code')) + '</span>'
        + '</button>'
        + '</div>'
        + (metaParts.length > 0
          ? '<div class="rl-coupon-meta">' + metaParts.join(' &middot; ') + '</div>'
          : '')
        + '</div>'
        + '</div>';
    });

    section.innerHTML = html;

    section.querySelectorAll('.rl-coupon-copy-btn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        copyCouponCode(btn.getAttribute('data-code'), btn);
      });
    });
  }

  function formatDiscount(type, value) {
    var val = parseFloat(value) || 0;
    if (type === 'fixed_amount' || type === 'fixed_cart') {
      return '$' + val.toFixed(2) + ' ' + t('discount_off');
    }
    if (type === 'percentage' || type === 'percent') {
      return val + '% ' + t('discount_off');
    }
    return value + ' ' + t('discount_off');
  }

  function handleCouponGenerate(campaignId, cardEl) {
    if (cardEl.classList.contains('rl-loading')) return;
    cardEl.classList.add('rl-loading');
    cardEl.classList.remove('can');

    var rightEl = cardEl.querySelector('.rl-redeem-r');
    if (rightEl) {
      rightEl.innerHTML = '<div class="rl-redeem-generating">'
        + '<div class="rl-spinner-sm"></div>'
        + '<span>' + esc(t('generating')) + '</span>'
        + '</div>';
    }

    ajaxPost('reloopin_generate_coupon', { campaign_id: campaignId }, function (data) {
      cardEl.classList.remove('rl-loading');
      cardEl.innerHTML = buildCouponRevealHtml(data);
      cardEl.style.flexDirection = 'column';
      cardEl.style.alignItems    = 'stretch';
      cardEl.style.cursor        = 'default';

      var copyBtn = cardEl.querySelector('.rl-coupon-copy-btn');
      if (copyBtn) {
        copyBtn.addEventListener('click', function (e) {
          e.stopPropagation();
          copyCouponCode(data.code, copyBtn);
        });
      }

      // Update balance after points deduction.
      if (data.balance) {
        applyUserData(data.balance);
        var redeemGroup = document.querySelector('#rl-redeem-content .rl-group span');
        if (redeemGroup) {
          var newPts = Number(data.balance.available_points || 0);
          redeemGroup.textContent = t('redeem_your_points', [newPts.toLocaleString()]);
        }
        campaignsLoaded = false;
        couponsLoaded   = false;
      }
    }, function () {
      cardEl.classList.remove('rl-loading');
      cardEl.classList.add('can');
      renderRedeemTab(campaignsData);
      showToast(t('coupon_error'));
    });
  }

  function buildCouponRevealHtml(data) {
    var discount   = formatDiscount(data.discount_type, data.discount_value);
    var expiryHtml = '';
    if (data.expires_at) {
      var d = new Date(data.expires_at);
      if (!isNaN(d.getTime())) {
        expiryHtml = '<div class="rl-coupon-expires">' + esc(t('discount_expires')) + ' '
          + esc(d.toLocaleDateString()) + '</div>';
      }
    }
    return '<div class="rl-coupon-reveal">'
      + '<div class="rl-coupon-label">' + esc(t('coupon_generated')) + ' \u2014 ' + esc(discount) + '</div>'
      + '<div class="rl-coupon-box">'
      + '<span class="rl-coupon-code">' + esc(data.code) + '</span>'
      + '<button type="button" class="rl-coupon-copy-btn">'
      + '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="' + primary + '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>'
      + '<span class="rl-coupon-copy-label">' + esc(t('copy_code')) + '</span>'
      + '</button>'
      + '</div>'
      + expiryHtml
      + '<div class="rl-coupon-hint">' + esc(t('auto_applied')) + '</div>'
      + '</div>';
  }

  function copyCouponCode(code, btnEl) {
    var labelEl = btnEl ? btnEl.querySelector('.rl-coupon-copy-label') : null;
    function onCopied() {
      if (btnEl) btnEl.classList.add('copied');
      if (labelEl) labelEl.textContent = t('copied');
      showToast(t('coupon_copied', [code]));
      setTimeout(function () {
        if (btnEl) btnEl.classList.remove('copied');
        if (labelEl) labelEl.textContent = t('copy_code');
      }, 2200);
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(code).then(onCopied).catch(function () {
        fallbackCopy(code);
        onCopied();
      });
    } else {
      fallbackCopy(code);
      onCopied();
    }
  }

  // ── Guest panel: fetch rules & campaigns for accordions ────────────────

  function fetchGuestRules() {
    ajaxPost('reloopin_launcher_rules', null, function (rules) {
      guestRulesLoaded = true;
      renderGuestAccordionRows(guestEarnBody, rules || [], 'earn');
    }, function () {
      guestRulesLoaded = true;
      if (guestEarnBody) {
        guestEarnBody.innerHTML = '<div style="text-align:center;color:#9B96B0;font-size:.7rem;padding:.6rem 0">'
          + esc(t('earn_error')) + '</div>';
      }
    });
  }

  function fetchGuestCampaigns() {
    ajaxPost('reloopin_launcher_campaigns', null, function (campaigns) {
      guestCampaignsLoaded = true;
      renderGuestAccordionRows(guestRedeemBody, campaigns || [], 'redeem');
    }, function () {
      guestCampaignsLoaded = true;
      if (guestRedeemBody) {
        guestRedeemBody.innerHTML = '<div style="text-align:center;color:#9B96B0;font-size:.7rem;padding:.6rem 0">'
          + esc(t('campaigns_error')) + '</div>';
      }
    });
  }

  function renderGuestAccordionRows(container, items, mode) {
    if (!container) return;

    if (!items || items.length === 0) {
      var emptyMsg = mode === 'earn' ? t('no_earn_rules') : t('no_redeem_options');
      container.innerHTML = '<div style="text-align:center;color:#9B96B0;font-size:.7rem;padding:.6rem 0">'
        + esc(emptyMsg) + '</div>';
      return;
    }

    var html = '';
    items.forEach(function (item) {
      if (mode === 'earn') {
        var cfg = EVENT_TYPE_CONFIG[item.event_type] || EVENT_TYPE_CONFIG.other;
        var strokeColor = STROKE_COLORS[cfg.color] || primary;
        var bgColor = cfg.color === 'grn' ? '#ECFDF5'
          : cfg.color === 'amb' ? '#FFFBEB'
          : cfg.color === 'fuch' ? ('color-mix(in srgb, ' + accent + ' 10%, white)')
          : ('color-mix(in srgb, ' + primary + ' 12%, white)');
        var iconSvg = (SVG_ICONS[cfg.icon] || SVG_ICONS.star)
          .replace(/width="14"/g, 'width="11"')
          .replace(/height="14"/g, 'height="11"')
          .replace(/stroke="currentColor"/g, 'stroke="' + strokeColor + '"');

        var ptsDisplay = item.rule_type === 'multiplier'
          ? 'x' + Number(item.earn_rate || 0).toLocaleString() + ' pts'
          : Number(item.earn_rate || 0).toLocaleString() + ' pts';

        var subtitle = item.description || (item.rule_type === 'multiplier'
          ? t('pts_per_dollar', [item.earn_rate])
          : t('one_time_bonus'));

        html += '<div class="rl-accord-row">'
          + '<div class="rl-accord-row-icon" style="background:' + bgColor + '">' + iconSvg + '</div>'
          + '<div class="rl-accord-row-body"><div class="rl-accord-row-title">' + esc(item.name || cfg.label) + '</div>'
          + '<div class="rl-accord-row-sub">' + esc(subtitle) + '</div></div>'
          + '<div class="rl-accord-row-pts">' + esc(ptsDisplay) + '</div>'
          + '</div>';
      } else {
        // Redeem (campaigns)
        var discount = formatDiscount(item.discount_type, item.discount_value);
        html += '<div class="rl-accord-row">'
          + '<div class="rl-accord-row-icon" style="background:color-mix(in srgb, ' + accent + ' 10%, white)">'
          + '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="' + accent + '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>'
          + '</div>'
          + '<div class="rl-accord-row-body"><div class="rl-accord-row-title">' + esc(item.name) + '</div>'
          + (item.description ? '<div class="rl-accord-row-sub">' + esc(item.description) + '</div>' : '') + '</div>'
          + '<div class="rl-accord-row-pts" style="color:' + accent + '">' + esc(Number(item.points_cost || 0).toLocaleString()) + ' pts</div>'
          + '</div>';
      }
    });

    if (!html) {
      var emptyMsg2 = mode === 'earn' ? t('no_earn_rules') : t('no_redeem_options');
      container.innerHTML = '<div style="text-align:center;color:#9B96B0;font-size:.7rem;padding:.6rem 0">'
        + esc(emptyMsg2) + '</div>';
      return;
    }

    container.innerHTML = html;
  }

  // ── Guest accordion toggle ────────────────────────────────────────────
  if (panelGuest) {
    panelGuest.addEventListener('click', function (e) {
      var head = e.target.closest('.rl-accord-head');
      if (head) {
        head.parentElement.classList.toggle('open');
      }
    });
  }

  // ── Fetch history ──────────────────────────────────────────────────────
  function fetchHistory(page, filter) {
    historyPage = page || 1;
    if (filter !== undefined) historyFilter = filter;

    var loading = document.getElementById('rl-hist-loading');
    var list    = document.getElementById('rl-hist-list');
    var empty   = document.getElementById('rl-hist-empty');
    var err     = document.getElementById('rl-hist-err');
    var pag     = document.getElementById('rl-hist-pagination');

    if (loading) loading.style.display = '';
    if (list) list.style.display = 'none';
    if (empty) empty.style.display = 'none';
    if (err) err.style.display = 'none';
    if (pag) pag.style.display = 'none';

    var postData = { page: historyPage };
    if (historyFilter) postData.entry_type = historyFilter;

    ajaxPost('reloopin_launcher_history', postData, function (data) {
      histLoaded = true;
      var results = (data && data.results) || [];

      if (results.length === 0) {
        if (loading) loading.style.display = 'none';
        if (empty) empty.style.display = '';
        return;
      }

      renderHistoryList(results);
      if (loading) loading.style.display = 'none';
      if (list) list.style.display = '';

      // Pagination
      var total = data.total || 0;
      var pageSize = data.page_size || 10;
      var totalPages = pageSize > 0 ? Math.ceil(total / pageSize) : 1;

      if (totalPages > 1 && pag) {
        var pageInfo = document.getElementById('rl-hist-page-info');
        var prevBtn = document.getElementById('rl-hist-prev');
        var nextBtn = document.getElementById('rl-hist-next');
        if (pageInfo) pageInfo.textContent = 'Page ' + historyPage + ' of ' + totalPages;
        if (prevBtn) prevBtn.disabled = (historyPage <= 1);
        if (nextBtn) nextBtn.disabled = (historyPage >= totalPages);
        pag.style.display = '';
      }
    }, function () {
      histLoaded = true;
      if (loading) loading.style.display = 'none';
      if (err) err.style.display = '';
    });
  }

  var TX_ICONS = {
    earn:    { cls: 'tx-earn',   color: '#059669', icon: 'cart' },
    redeem:  { cls: 'tx-redeem', color: accent,    icon: 'heart' },
    bonus:   { cls: 'tx-bonus',  color: '#D97706', icon: 'star' },
    expire:  { cls: 'tx-expire', color: '#DC2626', icon: 'tag' },
    void:    { cls: 'tx-expire', color: '#DC2626', icon: 'tag' },
    adjust:  { cls: 'tx-tier',   color: '#D97706', icon: 'star' },
    default: { cls: 'tx-ref',    color: primary,   icon: 'star' }
  };

  function renderHistoryList(results) {
    var list = document.getElementById('rl-hist-list');
    if (!list) return;

    // Group by month
    var months = {};
    results.forEach(function (entry) {
      var d = new Date(entry.date_raw || entry.date);
      var key = isNaN(d.getTime()) ? t('unknown') : d.toLocaleString('default', { month: 'long', year: 'numeric' });
      if (!months[key]) months[key] = [];
      months[key].push(entry);
    });

    var html = '';
    Object.keys(months).forEach(function (month) {
      html += '<div class="rl-month-label"><span>' + esc(month) + '</span><span class="rl-ml-line"></span></div>';
      months[month].forEach(function (entry) {
        var type = entry.entry_type || 'default';
        var txCfg = TX_ICONS[type] || TX_ICONS.default;
        var iconSvg = (SVG_ICONS[txCfg.icon] || SVG_ICONS.star).replace(/stroke="currentColor"/g, 'stroke="' + txCfg.color + '"').replace(/width="14"/g, 'width="13"').replace(/height="14"/g, 'height="13"');

        var pts = entry.points || 0;
        var sign = pts >= 0 ? '+' : '';
        var ptsCls = pts >= 0 ? 'pos' : (type === 'expire' ? 'exp' : 'neg');

        var title = entry.notes ? esc(entry.notes) : esc(capitalize(type));
        var date = esc(entry.date || '');

        html += '<div class="rl-tx" data-type="' + esc(type) + '">' +
          '<div class="rl-tx-icon ' + txCfg.cls + '">' + iconSvg + '</div>' +
          '<div class="rl-tx-body"><div class="rl-tx-title">' + title + '</div><div class="rl-tx-meta">' + date + '</div></div>' +
          '<div class="rl-tx-r"><div class="rl-tx-pts ' + ptsCls + '">' + sign + Number(pts).toLocaleString() + ' pts</div>' +
          '<div class="rl-tx-bal">bal. ' + Number(entry.balance_after || 0).toLocaleString() + '</div></div></div>';
      });
    });

    list.innerHTML = html;
  }

  // ── History filters ────────────────────────────────────────────────────
  var filterRow = document.getElementById('rl-filter-row');
  if (filterRow) {
    filterRow.addEventListener('click', function (e) {
      var btn = e.target.closest('.rl-filter');
      if (!btn) return;
      filterRow.querySelectorAll('.rl-filter').forEach(function (f) { f.classList.remove('on'); });
      btn.classList.add('on');
      var filter = btn.getAttribute('data-filter') || '';
      fetchHistory(1, filter);
    });
  }

  // ── History pagination ─────────────────────────────────────────────────
  var histPrev = document.getElementById('rl-hist-prev');
  var histNext = document.getElementById('rl-hist-next');
  if (histPrev) histPrev.addEventListener('click', function () {
    if (historyPage > 1) fetchHistory(historyPage - 1);
  });
  if (histNext) histNext.addEventListener('click', function () {
    fetchHistory(historyPage + 1);
  });

  // ── Modals ─────────────────────────────────────────────────────────────
  var backdrop = document.getElementById('rl-backdrop');

  function openModal(id) {
    var modal = document.getElementById(id);
    if (modal) modal.classList.add('show');
    if (backdrop) backdrop.classList.add('show');
  }

  function closeModal(id) {
    var modal = document.getElementById(id);
    if (modal) modal.classList.remove('show');
    if (backdrop) backdrop.classList.remove('show');
  }

  function closeAllModals() {
    closeModal('rl-bday-modal');
    closeModal('rl-ref-modal');
  }

  if (backdrop) backdrop.addEventListener('click', closeAllModals);

  // Birthday modal
  var bdayClose = document.getElementById('rl-bday-close');
  if (bdayClose) bdayClose.addEventListener('click', function () { closeModal('rl-bday-modal'); });

  var bdaySave = document.getElementById('rl-bday-save');
  if (bdaySave) {
    bdaySave.addEventListener('click', function () {
      var month = document.getElementById('rl-bday-month');
      var day = document.getElementById('rl-bday-day');
      var err = document.getElementById('rl-bday-error');
      var monthVal = month ? month.value : '';
      var dayVal = day ? parseInt(day.value, 10) : 0;

      if (!monthVal || !dayVal || dayVal < 1 || dayVal > 31) {
        if (err) err.classList.add('show');
        return;
      }
      if (err) err.classList.remove('show');

      bdaySave.disabled = true;
      ajaxPost('reloopin_save_birthday', { month: monthVal, day: dayVal }, function () {
        closeModal('rl-bday-modal');
        showToast(t('bday_saved'));

        // Update in-memory earn status so re-render is instant
        if (!earnStatus) earnStatus = { completed: [], birthday_set: false };
        earnStatus.birthday_set = true;
        if (earnStatus.completed.indexOf('birthday') === -1) {
          earnStatus.completed.push('birthday');
        }
        renderEarnTab(currentRules);

        bdaySave.disabled = false;
      }, function () {
        bdaySave.disabled = false;
        showToast('Could not save birthday. Please try again.');
      });
      return;
    });
  }

  // Referral modal
  var refClose = document.getElementById('rl-ref-close');
  if (refClose) refClose.addEventListener('click', function () { closeModal('rl-ref-modal'); });

  // Copy referral link
  var refCopyBtn = document.getElementById('rl-ref-copy-btn');
  if (refCopyBtn) {
    refCopyBtn.addEventListener('click', function () {
      var linkText = document.getElementById('rl-ref-link-text');
      if (!linkText || !linkText.textContent) return;

      var text = linkText.textContent;
      var label = document.getElementById('rl-ref-copy-label');

      function onCopied() {
        refCopyBtn.classList.add('copied');
        if (label) label.textContent = 'Copied!';
        showToast(t('referral_copied'));
        setTimeout(function () {
          refCopyBtn.classList.remove('copied');
          if (label) label.textContent = 'Copy';
        }, 2200);
      }

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(onCopied).catch(function () {
          fallbackCopy(text);
          onCopied();
        });
      } else {
        fallbackCopy(text);
        onCopied();
      }
    });
  }

  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) { /* silent */ }
    document.body.removeChild(ta);
  }

  // Share buttons
  root.querySelectorAll('.rl-share-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var linkEl = document.getElementById('rl-ref-link-text');
      var url = linkEl ? linkEl.textContent : '';
      var type = btn.getAttribute('data-share');
      // TODO: Wire actual share URLs
      var shareUrl = '';
      if (type === 'facebook') shareUrl = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url);
      if (type === 'twitter') shareUrl = 'https://twitter.com/intent/tweet?url=' + encodeURIComponent(url);
      if (type === 'whatsapp') shareUrl = 'https://wa.me/?text=' + encodeURIComponent(url);
      if (type === 'email') shareUrl = 'mailto:?body=' + encodeURIComponent(url);
      if (shareUrl) window.open(shareUrl, '_blank', 'width=600,height=400,noopener');
      closeModal('rl-ref-modal');
    });
  });

  // ── Apply at checkout ──────────────────────────────────────────────────
  var applyBtn = document.getElementById('rl-apply-btn');
  if (applyBtn) {
    applyBtn.addEventListener('click', function () {
      var redeemTab = root.querySelector('[data-tab="redeem"]');
      if (redeemTab) redeemTab.click();
    });
  }

  // ── Toast ──────────────────────────────────────────────────────────────
  function showToast(msg) {
    var toast = document.getElementById('rl-toast');
    var text = document.getElementById('rl-toast-text');
    if (!toast || !text) return;
    text.textContent = msg;
    toast.classList.add('show');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.classList.remove('show'); }, 3200);
  }

  // ── Utilities ──────────────────────────────────────────────────────────
  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function capitalize(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
  }

})();
