<?php
/**
 * AuraGharTak — public patient shop (no login).
 * Retail parity: aura_ghar_tak_shop.php — opens from WhatsApp stock link ?token=
 * Uses Advanced API: GET /api/public/agt/shop, POST /api/public/agt/orders
 */
declare(strict_types=1);

$token = isset($_GET['token']) ? preg_replace('/[^a-fA-F0-9]/', '', (string) $_GET['token']) : '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$apiBase = $scheme . '://' . $host . '/api/public/agt';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0d9488">
<title>Order Medicines Online — AuraGharTak</title>
<style>
:root{--brand:#0e9384;--brand-dark:#0a7568;--ink:#101828;--muted:#667085;--line:#e4e9f0;--bg:#f3f6f9}
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--ink)}
.top{background:linear-gradient(120deg,#0b4f47,#0e9384);color:#fff;padding:14px 16px}
.top h1{margin:0;font-size:1.05rem}.top p{margin:4px 0 0;font-size:.8rem;opacity:.9}
.wrap{max-width:960px;margin:0 auto;padding:12px 16px 100px}
.search{margin:12px 0;display:flex;gap:8px}.search input{flex:1;padding:10px 14px;border:1px solid var(--line);border-radius:999px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px}
.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:10px;display:flex;flex-direction:column;gap:6px}
.card h3{margin:0;font-size:.85rem;line-height:1.3;min-height:2.6em}
.meta{font-size:.72rem;color:var(--muted)}.price{font-weight:800;font-size:.95rem}
.qty{display:flex;align-items:center;justify-content:space-between;background:var(--brand);border-radius:8px;overflow:hidden;height:34px;margin-top:auto}
.qty button{width:36px;height:100%;border:none;background:transparent;color:#fff;font-size:1.1rem;cursor:pointer}
.qty span{flex:1;text-align:center;color:#fff;font-weight:700;font-size:.85rem}
.btn-add{width:100%;border:none;background:var(--brand);color:#fff;border-radius:8px;height:34px;font-weight:700;cursor:pointer}
.cartbar{position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid var(--line);padding:12px 16px;display:none;gap:10px;align-items:center;justify-content:space-between;box-shadow:0 -4px 20px rgba(0,0,0,.08)}
.cartbar.show{display:flex}.btn{ border:none;background:var(--brand-dark);color:#fff;padding:10px 18px;border-radius:10px;font-weight:700;cursor:pointer}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);align-items:flex-end;justify-content:center;z-index:50}
.modal.show{display:flex}.sheet{background:#fff;width:100%;max-width:520px;max-height:85vh;overflow:auto;border-radius:16px 16px 0 0;padding:16px}
.err,.ok{max-width:480px;margin:40px auto;padding:28px 22px;border-radius:16px;text-align:center;background:#fff;border:1px solid var(--line);box-shadow:0 12px 32px rgba(15,23,42,.08)}
.err{background:#fef2f2;color:#b91c1c;border-color:#fecaca}
.ok-icon{width:72px;height:72px;margin:0 auto 16px;border-radius:50%;background:linear-gradient(135deg,#14b8a6,#0e9384);color:#fff;display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:700}
.ok h2{margin:0 0 8px;font-size:1.35rem;color:var(--ink)}
.ok-sub{color:var(--muted);font-size:.92rem;margin-bottom:16px}
.ok-no{display:inline-block;border:1.5px dashed var(--brand);color:var(--brand-dark);font-weight:800;padding:10px 18px;border-radius:999px;margin:12px 0;font-size:.95rem}
.ok-total{font-size:1.05rem;font-weight:700;margin:8px 0 16px}
.ok-foot{font-size:.85rem;color:var(--muted);line-height:1.5;max-width:360px;margin:0 auto}
.loading{text-align:center;padding:40px;color:var(--muted)}
textarea{width:100%;min-height:60px;border:1px solid var(--line);border-radius:8px;padding:8px;font-family:inherit}
</style>
</head>
<body>
<div id="root"><div class="loading">Loading your pharmacy catalogue…</div></div>
<script>
(function () {
  var TOKEN = <?php echo json_encode($token, JSON_THROW_ON_ERROR); ?>;
  var API = <?php echo json_encode($apiBase, JSON_THROW_ON_ERROR); ?>;
  var state = { pharmacy: null, patient: null, products: [], cart: {}, q: '', rxB64: '' };

  function el(html) {
    var d = document.createElement('div');
    d.innerHTML = html;
    return d.firstElementChild;
  }
  function money(n) { return '₹' + (Number(n) || 0).toFixed(2); }
  function keyOf(p) { return (p.p_name || '') + '|' + (p.batch_no || ''); }
  function cartCount() {
    var n = 0;
    Object.keys(state.cart).forEach(function (k) { n += state.cart[k].qty; });
    return n;
  }
  function cartTotal() {
    var t = 0;
    Object.keys(state.cart).forEach(function (k) {
      var it = state.cart[k];
      t += (Number(it.unit_mrp) || 0) * it.qty;
    });
    return t;
  }
  function apiGet(path) {
    return fetch(API + path, { credentials: 'omit' }).then(function (r) { return r.json(); });
  }
  function apiPost(path, body) {
    return fetch(API + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'omit',
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }

  function loadShop() {
    var q = state.q ? '&q=' + encodeURIComponent(state.q) : '';
    return apiGet('/shop?token=' + encodeURIComponent(TOKEN) + q).then(function (res) {
      if (!res || !res.success || !res.data) {
        throw new Error((res && res.message) || 'Invalid or expired link');
      }
      state.pharmacy = res.data.pharmacy;
      state.patient = res.data.patient;
      state.products = res.data.products || [];
    });
  }

  function changeQty(p, delta) {
    var k = keyOf(p);
    var cur = state.cart[k] ? state.cart[k].qty : 0;
    var next = cur + delta;
    var max = Math.floor(Number(p.availableQty) || 0);
    var unit = Number(p.unit_mrp || p.mrp) || 0;
    if (next <= 0) delete state.cart[k];
    else if (next <= max) {
      state.cart[k] = { p_name: p.p_name, batch_no: p.batch_no || '', qty: next, unit_mrp: unit };
    }
    render();
  }

  function render() {
    var root = document.getElementById('root');
    if (!state.pharmacy) return;
    var ph = state.pharmacy;
    var pt = state.patient || {};
    var html = '<div class="top"><h1>' + escapeHtml(ph.name || 'Pharmacy') + '</h1>';
    html += '<p>Ordering for <strong>' + escapeHtml(pt.name || 'Patient') + '</strong>';
    if (pt.contact) html += ' · ' + escapeHtml(pt.contact);
    html += '</p></div><div class="wrap">';
    html += '<div class="search"><input id="q" placeholder="Search medicine…" value="' + escapeHtml(state.q) + '">';
    html += '<button class="btn" type="button" id="btnSearch">Search</button></div>';
    html += '<div class="grid">';
    if (!state.products.length) {
      html += '<p class="meta">No in-stock products found.</p>';
    }
    state.products.forEach(function (p) {
      var k = keyOf(p);
      var qty = state.cart[k] ? state.cart[k].qty : 0;
      html += '<div class="card"><h3>' + escapeHtml(p.p_name) + '</h3>';
      html += '<div class="meta">Batch ' + escapeHtml(p.batch_no || '-') + ' · Stock ' + (p.availableQty || 0) + '</div>';
      html += '<div class="price">' + money(p.unit_mrp || p.mrp) + ' / strip</div>';
      if (qty > 0) {
        html += '<div class="qty"><button type="button" data-act="dec" data-k="' + escapeAttr(k) + '">−</button>';
        html += '<span>' + qty + '</span><button type="button" data-act="inc" data-k="' + escapeAttr(k) + '">+</button></div>';
      } else {
        html += '<button type="button" class="btn-add" data-act="add" data-k="' + escapeAttr(k) + '">Add</button>';
      }
      html += '</div>';
    });
    html += '</div></div>';
    html += '<div class="cartbar' + (cartCount() ? ' show' : '') + '" id="cartbar">';
    html += '<div><strong>' + cartCount() + ' item(s)</strong><br><span>' + money(cartTotal()) + ' est.</span></div>';
    html += '<button type="button" class="btn" id="btnCheckout">Review &amp; place order</button></div>';
    html += '<div class="modal" id="modal"><div class="sheet"><h2>Confirm order</h2><div id="lines"></div>';
    html += '<p><label>Note for pharmacy<br><textarea id="note"></textarea></label></p>';
    html += '<p><label>Prescription (optional)<br><input type="file" id="rx" accept="image/*,.pdf"></label></p>';
    html += '<div style="display:flex;gap:8px;margin-top:12px">';
    html += '<button type="button" class="btn" id="btnPlace">Place order</button>';
    html += '<button type="button" class="btn" style="background:#64748b" id="btnClose">Close</button></div></div></div>';
    root.innerHTML = html;

    document.getElementById('btnSearch').onclick = function () {
      state.q = (document.getElementById('q').value || '').trim();
      loadShop().then(render).catch(showErr);
    };
    root.querySelectorAll('[data-act]').forEach(function (btn) {
      btn.onclick = function () {
        var k = btn.getAttribute('data-k');
        var p = state.products.find(function (x) { return keyOf(x) === k; });
        if (!p) return;
        var act = btn.getAttribute('data-act');
        changeQty(p, act === 'dec' ? -1 : 1);
      };
    });
    var bar = document.getElementById('cartbar');
    if (bar) document.getElementById('btnCheckout').onclick = openModal;
    document.getElementById('btnClose').onclick = function () { document.getElementById('modal').classList.remove('show'); };
    document.getElementById('btnPlace').onclick = placeOrder;
    document.getElementById('rx').onchange = function (ev) {
      var f = ev.target.files && ev.target.files[0];
      if (!f) return;
      var r = new FileReader();
      r.onload = function () { state.rxB64 = String(r.result || ''); };
      r.readAsDataURL(f);
    };
  }

  function openModal() {
    var lines = document.getElementById('lines');
    var h = '';
    Object.keys(state.cart).forEach(function (k) {
      var it = state.cart[k];
      h += '<div class="meta">' + escapeHtml(it.p_name) + ' × ' + it.qty + ' — ' + money(it.unit_mrp * it.qty) + '</div>';
    });
    h += '<p><strong>Total: ' + money(cartTotal()) + '</strong></p>';
    lines.innerHTML = h;
    document.getElementById('modal').classList.add('show');
  }

  function placeOrder() {
    var items = Object.keys(state.cart).map(function (k) {
      var it = state.cart[k];
      return { p_name: it.p_name, batch_no: it.batch_no, qty: it.qty, unit_mrp: it.unit_mrp };
    });
    if (!items.length) return;
    var btn = document.getElementById('btnPlace');
    btn.disabled = true;
    btn.textContent = 'Placing…';
    var body = {
      token: TOKEN,
      items: items,
      patient_note: (document.getElementById('note').value || '').trim()
    };
    if (state.rxB64) body.prescription_base64 = state.rxB64;
    apiPost('/orders', body).then(function (res) {
      if (!res || !res.success) throw new Error((res && res.message) || 'Order failed');
      var d = res.data || {};
      document.getElementById('root').innerHTML =
        '<div class="ok">' +
        '<div class="ok-icon">✓</div>' +
        '<h2>Order placed successfully</h2>' +
        '<p class="ok-sub">Thank you! Your order has been sent to the pharmacy.</p>' +
        '<div class="ok-no">Order no: ' + escapeHtml(d.order_no || '') + '</div>' +
        '<p class="ok-total">Estimated total: ' + money(d.estimated_total) + '</p>' +
        '<p class="ok-foot">The pharmacy will prepare your medicines and update you on WhatsApp with the bill and delivery status.</p>' +
        '</div>';
    }).catch(function (e) {
      btn.disabled = false;
      btn.textContent = 'Place order';
      alert(e.message || 'Could not place order');
    });
  }

  function escapeHtml(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  }
  function escapeAttr(s) { return escapeHtml(s).replace(/'/g, '&#39;'); }
  function showErr(e) {
    document.getElementById('root').innerHTML =
      '<div class="err"><h2>Link not available</h2><p>' + escapeHtml(e.message || 'Invalid link') + '</p></div>';
  }

  if (!TOKEN || TOKEN.length < 16) {
    showErr(new Error('Missing or invalid token in the link.'));
    return;
  }
  loadShop().then(render).catch(showErr);
})();
</script>
</body>
</html>
