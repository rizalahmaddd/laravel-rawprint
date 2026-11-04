/*! rawprint-bridge.js - Laravel bridge for RawPrint Agent. MIT License. */
(function () {
  'use strict';

  if (window.RawPrintBridge) return;

  if (typeof RawPrint === 'undefined') {
    if (window.console) console.error('[rawprint] load rawprint.js before rawprint-bridge.js');
    return;
  }

  function readConfig() {
    var merged = { jobs: [] };

    function merge(data) {
      if (!data || typeof data !== 'object') return;

      for (var key in data) {
        if (!Object.prototype.hasOwnProperty.call(data, key)) continue;

        if (key === 'jobs') {
          merged.jobs = merged.jobs.concat(data.jobs || []);
        } else if (merged[key] === undefined) {
          merged[key] = data[key];
        }
      }
    }

    merge(window.__RAWPRINT__);

    var nodes = document.querySelectorAll ? document.querySelectorAll('script[data-rawprint-config]') : [];

    for (var i = 0; i < nodes.length; i++) {
      try {
        merge(JSON.parse(nodes[i].textContent || ''));
      } catch (e) {
        // Ignore a malformed config block and keep the rest.
      }
    }

    return merged;
  }

  var cfg = readConfig();
  var agent = new RawPrint({ baseUrl: cfg.baseUrl, appName: cfg.appName });
  var chain = Promise.resolve();
  var started = false;

  function emit(name, detail) {
    try {
      window.dispatchEvent(new CustomEvent('rawprint:' + name, { detail: detail }));
    } catch (e) {
      // CustomEvent is not available in very old browsers.
    }
  }

  function normalize(job) {
    return {
      jobId: job.jobId || job.job_id,
      dataBase64: job.dataBase64 || job.data_base64,
      printer: job.printer || '',
      docName: job.docName || job.doc_name || '',
      copies: job.copies || 1
    };
  }

  function modal() {
    function el(tag, style, text) {
      var node = document.createElement(tag);
      if (style) node.style.cssText = style;
      if (text) node.textContent = text;
      return node;
    }

    var overlay = el('div', 'position:fixed;inset:0;z-index:2147483647;background:rgba(0,0,0,.45);' +
      'display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,sans-serif');
    overlay.setAttribute('data-rawprint-modal', '');

    var box = el('div', 'background:#fff;color:#111;max-width:380px;width:calc(100% - 32px);' +
      'border-radius:12px;padding:24px;box-shadow:0 20px 50px rgba(0,0,0,.3)');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');

    var title = el('h2', 'margin:0 0 8px;font-size:18px', 'Approve printing');
    var text = el('p', 'margin:0 0 16px;font-size:14px;line-height:1.5;color:#555',
      'Open RawPrint Agent on this computer and approve this code:');

    var code = el('div', 'font-size:34px;font-weight:700;letter-spacing:8px;text-align:center;' +
      'margin:8px 0 16px;font-variant-numeric:tabular-nums', '------');
    code.setAttribute('data-rawprint-code', '');

    var actions = el('div', 'display:flex;gap:8px');

    var approve = el('a', 'flex:1;text-align:center;background:#1f5fd1;color:#fff;text-decoration:none;' +
      'padding:10px 14px;border-radius:8px;font-size:14px', 'Open settings');
    approve.setAttribute('data-rawprint-approve', '');
    approve.setAttribute('href', 'http://127.0.0.1:18310/#pair');
    approve.setAttribute('target', '_blank');
    approve.setAttribute('rel', 'noopener');

    var cancel = el('button', 'background:#eee;border:0;padding:10px 14px;border-radius:8px;' +
      'font-size:14px;cursor:pointer', 'Cancel');
    cancel.setAttribute('data-rawprint-cancel', '');
    cancel.setAttribute('type', 'button');

    actions.appendChild(approve);
    actions.appendChild(cancel);
    box.appendChild(title);
    box.appendChild(text);
    box.appendChild(code);
    box.appendChild(actions);
    overlay.appendChild(box);
    document.body.appendChild(overlay);

    var ui = {
      code: code,
      approve: approve,
      cancel: cancel,
      close: function () {
        if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
      }
    };

    cancel.addEventListener('click', ui.close);

    return ui;
  }

  function pair() {
    if (cfg.prompt === false) {
      return agent.pair(function () {});
    }

    var ui = null;

    function show(code, url) {
      if (!document.body) {
        throw new Error('RawPrint pairing needs a rendered document body');
      }

      if (!ui) ui = modal();

      ui.code.textContent = code;
      if (url) ui.approve.href = url;
    }

    function close() {
      if (ui) ui.close();
    }

    return agent.pair(show).then(function (result) {
      close();
      return result;
    }, function (err) {
      close();
      throw err;
    });
  }

  function ensurePaired() {
    return agent.detect().then(function (health) {
      if (!health.running) {
        var err = new Error('RawPrint Agent is not running on this computer, or this origin is not allowlisted');
        err.code = 'agent_unreachable';
        emit('unavailable', err);
        throw err;
      }

      if (health.paired) return health;

      return pair().then(function () {
        return health;
      });
    });
  }

  function send(input) {
    var job = normalize(input);

    var run = chain.then(function () {
      return ensurePaired()
        .then(function () {
          emit('job:queued', job);
          return agent.print(job);
        })
        .then(function (res) {
          var id = res && res.job ? res.job.job_id : job.jobId;

          return agent.waitJob(id).then(function (final) {
            emit(final.state === 'done' ? 'job:done' : 'job:pending', final);
            return final;
          });
        });
    }).catch(function (err) {
      emit('job:error', { job: job, error: err });
      throw err;
    });

    chain = run.then(function () {}, function () {});

    return run;
  }

  function start() {
    if (started) return;
    started = true;

    cfg = readConfig();

    if (window.RawPrintBridge) window.RawPrintBridge.jobs = cfg.jobs || [];

    var jobs = cfg.jobs || [];

    if (cfg.autoSend === false || !jobs.length) return;

    jobs.forEach(function (job) {
      send(job).catch(function (err) {
        if (window.console) console.error('[rawprint]', err);
      });
    });
  }

  window.RawPrintBridge = {
    agent: agent,
    jobs: cfg.jobs || [],
    send: send,
    ensurePaired: ensurePaired,
    start: start
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
