/* map.js — territories as points on a grey Esri canvas.
 *
 * One implementation, two pages. A page declares a map by marking a container
 * and setting attributes on it; nothing else is needed, and no page carries an
 * inline script:
 *
 *   data-only        "flagged" (home page) or "all" (map page). Decides both
 *                    what is requested from the API and what is drawn — the
 *                    home page never downloads 3,866 rows to show 35.
 *   data-caption     CSS selector of an element to write a summary line into.
 *   data-scroll-zoom present to allow wheel zoom. Off by default so a map
 *                    embedded in a scrolling page does not trap the scroll.
 *
 * Setting data-list turns it into the exploration view: a list beside the map,
 * the two kept in sync.
 *
 *   data-list        CSS selector of the container to build the list into.
 *   data-filters     CSS selector of the container for the filter controls.
 *   data-count       CSS selector of an element for the "N on the map · M listed" line.
 *
 * Everything inside the map container — the Leaflet canvas, the loading
 * overlay, the legend — is built here.
 *
 * KEEPING THE SYNC CHEAP AT 3,866 POINTS
 * --------------------------------------
 * Two things could make hovering stutter, and only one of them is the lookup.
 *
 * The lookup is solved by `byId`, built while the markers are created.
 *
 * The other is Leaflet's canvas renderer. Restyling a single marker calls
 * _updateStyle -> _requestRedraw -> _redraw -> _draw, and _draw walks the
 * renderer's entire layer list (`for (n = this._drawFirst; n; n = n.next)`).
 * The repaint itself is clipped to the changed bounds, so it is not a full
 * canvas repaint — but that walk happens on every hover, over 3,866 layers.
 *
 * So the highlight is never a restyle. It is one circle on its own renderer,
 * in its own pane, moved with setLatLng. That renderer holds a single object,
 * so the walk is over one layer instead of 3,866, and the point canvas is
 * never touched at all. The cost does not grow with the dataset.
 *
 * The other direction is already handled by Leaflet, which throttles its own
 * hover hit-testing behind _mouseHoverThrottled. On top of that, this file
 * ignores a hover naming the id it is already showing, and coalesces the rest
 * into one update per animation frame.
 */

(function () {
  'use strict';

  var el = DM.el;

  /* Bordeaux only for the flagged points. The rest stay muted: a territory
     with no alert this week is not "fine", it simply did not change, and a
     colour that reads as approval would say otherwise.

     Read from the stylesheet's --map-* tokens rather than written here, so
     the points change with the theme and match the legend, which is drawn
     from the same tokens. */
  function mapColors() {
    return {
      flagged:   DM.cssVar('--map-flagged'),
      ring:      DM.cssVar('--map-ring'),        /* a flagged point's edge     */
      ringMuted: DM.cssVar('--map-ring-muted'),  /* every other point's edge   */
      loss:      DM.cssVar('--map-loss'),        /* lost forest, within its normal range */
      quiet:     DM.cssVar('--map-quiet')        /* no recorded loss           */
    };
  }

  var COLOR = mapColors();

  /* ---------- point appearance ---------- */

  /* In light, the ring around a flagged point is deep forest, not the white
     used by the same mark in the logo and the headings. Those sit on grounds
     we chose; this one sits on terrain, and the worst ground a basemap offers
     is a mid tone where a bordeaux disc and a white ring are BOTH weak —
     measured at 2.86:1 on the physical map and 2.89:1 on the grey canvas
     before it. A dark ring takes the worst case to 3.94:1.

     In dark the ground is a flat #333, where a dark ring disappears and a
     light one holds 11.4:1, so the ring turns light — and the mark becomes the
     logo's again, a bordeaux disc in a pale ring. */
  function styleFor(point) {
    if (point.flagged) {
      return {
        radius: 7,
        fillColor: COLOR.flagged,
        fillOpacity: 0.95,
        color: COLOR.ring,
        weight: 1.5
      };
    }
    /* The muted dots were unstroked, which was fine on a flat grey canvas but
       leaves them at 1.0:1 against the varied greens of a physical map. A dark
       ring, thinner, carries them without making them loud. It stays dark in
       the dark theme: a light ring there would make every quiet territory as
       visible as a flagged one. */
    if (point.lost_ha > 0) {
      return {
        radius: 3.5, fillColor: COLOR.loss, fillOpacity: 0.85,
        color: COLOR.ringMuted, weight: 0.75, opacity: 0.7
      };
    }
    return {
      radius: 2.5, fillColor: COLOR.quiet, fillOpacity: 0.6,
      color: COLOR.ringMuted, weight: 0.6, opacity: 0.55
    };
  }

  function popupFor(point) {
    return el('div', {}, [
      el('span', { class: 'popup-name', text: DM.territoryName(point.name, point.territory_id) }),
      el('span', { class: 'popup-country', text: point.country || DM.NO_DATA }),
      point.flagged ? el('span', { class: 'badge badge-flagged', text: 'Flagged' }) : null,
      point.flagged ? el('br') : null,
      el('span', {
        class: 'popup-loss',
        text: point.lost_ha > 0
          ? DM.ha(point.lost_ha) + ' lost this week'
          : 'No recorded loss this week'
      }),
      el('br'),
      el('a', {
        class: 'popup-link',
        href: 'territory.php?id=' + point.territory_id,
        text: 'View this territory →'
      })
    ]);
  }

  /* ---------- one map ---------- */

  function createMap(shell) {
    function target(attr) {
      var sel = shell.getAttribute(attr);
      return sel ? document.querySelector(sel) : null;
    }

    var only        = shell.getAttribute('data-only') === 'flagged' ? 'flagged' : 'all';
    var captionNode = target('data-caption');
    var listNode    = target('data-list');
    var filterNode  = target('data-filters');
    var countNode   = target('data-count');
    var isExplorer  = listNode !== null;

    /* --- scaffolding --- */

    var canvas  = el('div', { class: 'map-canvas' });
    var overlay = el('div', { class: 'map-overlay' });
    var legend  = el('div', { class: 'map-legend', hidden: true });

    shell.appendChild(canvas);
    shell.appendChild(legend);
    shell.appendChild(overlay);

    /* Only the full map needs a key; a flagged-only map has one kind of dot,
       and the caption underneath says what it is. */
    if (only === 'all') {
      DM.replace(legend, [
        el('p', { text: 'This week' }),
        el('span', {}, [el('i', { class: 'dot dot-flagged' }), ' Flagged']),
        el('span', {}, [el('i', { class: 'dot dot-loss' }), ' Lost forest, within its normal range']),
        el('span', {}, [el('i', { class: 'dot dot-quiet' }), ' No recorded loss'])
      ]);
    }

    /* --- Leaflet --- */

    var map = L.map(canvas, {
      preferCanvas: true,
      renderer: L.canvas({ padding: 0.5 }),
      center: [-4.5, -62],
      zoom: 4,
      minZoom: 3,
      worldCopyJump: false,
      scrollWheelZoom: shell.hasAttribute('data-scroll-zoom')
    });

    /* The topographic map in light, the dark grey canvas in dark, swapped
       when the theme changes. Which tiles, and why, is in format.js, where
       the territory page's map gets the same ones. */
    DM.basemap(map);

    /* Three layers so flagged points always sit above the muted ones. */
    var layers = {
      quiet:   L.layerGroup().addTo(map),
      loss:    L.layerGroup().addTo(map),
      flagged: L.layerGroup().addTo(map)
    };

    /* The highlight: one circle, its own renderer, its own pane above the
       point canvas. Moving it never touches the 3,866. */
    map.createPane('halo');
    map.getPane('halo').style.zIndex = '450';
    map.getPane('halo').style.pointerEvents = 'none';

    var halo = L.circleMarker([0, 0], {
      radius: 12,
      color: COLOR.flagged,
      weight: 3,
      opacity: 0.9,
      fill: false,
      interactive: false,
      renderer: L.svg({ pane: 'halo' })
    });

    /* A theme change repaints every point. That is 3,866 setStyle calls, but
       the canvas renderer gathers them into one redraw on the next frame, and
       it happens only when the reader or the system switches theme. */
    DM.onTheme(function () {
      COLOR = mapColors();
      halo.setStyle({ color: COLOR.flagged });
      entries.forEach(function (entry) { entry.marker.setStyle(styleFor(entry.point)); });
    });

    var entries = [];                        // { point, marker, layer, row }
    var byId = {};                           // territory_id -> entry
    var listWired = false;                   // the list's listeners, attached once
    var watchers = [];                       // told when the highlight moves
    var filter = { status: 'all', country: 'all' };
    var lastBounds = [];
    var userMoved = false;
    var fitting = false;
    var activeId = null;
    var selectedId = null;

    /* --- framing --- */

    map.on('movestart', function () { if (!fitting) userMoved = true; });

    /* Leaflet fixes the view against the container size it can measure at the
       time, and on the home page the list beside the map is filled after this
       file runs, which grows the container. Measuring first, and re-framing
       when the container changes size, is what keeps the points filling the
       map instead of huddling in a corner. */
    function frame(bounds) {
      if (bounds) lastBounds = bounds;
      fitting = true;
      map.invalidateSize({ animate: false });

      if (lastBounds.length > 1) {
        map.fitBounds(lastBounds, { padding: [16, 10], maxZoom: 7, animate: false });
      } else if (lastBounds.length === 1) {
        map.setView(lastBounds[0], 7, { animate: false });
      }
      fitting = false;
    }

    if (typeof ResizeObserver === 'function') {
      new ResizeObserver(function () {
        if (userMoved) {
          fitting = true;
          map.invalidateSize({ animate: false });
          fitting = false;
        } else {
          frame();
        }
      }).observe(shell);
    }

    /* --- the highlight, in both directions --- */

    function paintActive(id) {
      if (id === activeId) return;

      var previous = activeId !== null && byId[activeId];
      if (previous && previous.row) previous.row.classList.remove('is-active');

      activeId = id;

      var entry = id !== null ? byId[id] : null;
      if (entry) {
        if (entry.row) entry.row.classList.add('is-active');
        halo.setLatLng(entry.marker.getLatLng());
        if (!map.hasLayer(halo)) halo.addTo(map);
      } else if (map.hasLayer(halo)) {
        map.removeLayer(halo);
      }

      /* A list this file did not build — the home page's ranking, which comes
         from a different query — follows the highlight through here. */
      for (var i = 0; i < watchers.length; i++) watchers[i](id);
    }

    /* At most one repaint per frame, however fast the pointer moves. */
    var pendingId = null;
    var pendingFrame = null;

    function setActive(id) {
      if (id === activeId && pendingFrame === null) return;
      pendingId = id;
      if (pendingFrame !== null) return;
      pendingFrame = requestAnimationFrame(function () {
        pendingFrame = null;
        paintActive(pendingId);
      });
    }

    /* Published synchronously, before any data has arrived, so a script that
       runs after this one can attach without waiting. Unknown ids are simply
       ignored until the points load. */
    shell.dmMap = {
      highlight: function (id) { setActive(id); },
      onHighlight: function (fn) { watchers.push(fn); }
    };

    function setSelected(id) {
      var previous = selectedId !== null && byId[selectedId];
      if (previous && previous.row) previous.row.classList.remove('is-selected');

      selectedId = id;

      var entry = id !== null ? byId[id] : null;
      if (entry && entry.row && !entry.row.hidden) {
        entry.row.classList.add('is-selected');
        entry.row.scrollIntoView({ block: 'nearest' });
      }
    }

    /* --- filtering, entirely in the browser --- */

    function matches(point) {
      if (filter.country !== 'all' && point.country !== filter.country) return false;
      if (filter.status === 'flagged' && !point.flagged) return false;
      return true;
    }

    function applyFilter(refit) {
      var shown = 0;
      var listed = 0;
      var bounds = [];

      entries.forEach(function (entry) {
        var visible = matches(entry.point);
        var onMap = entry.layer.hasLayer(entry.marker);

        if (visible && !onMap) entry.layer.addLayer(entry.marker);
        else if (!visible && onMap) entry.layer.removeLayer(entry.marker);

        if (entry.row) {
          entry.row.hidden = !visible;
          if (visible) listed++;
        }

        if (visible) {
          shown++;
          bounds.push([entry.point.lat, entry.point.lon]);
        }
      });

      /* Two numbers because there are two panes: the map draws every
         territory, the list only those that lost forest. One figure would be
         read as describing whichever pane the reader happened to look at. */
      if (countNode) countNode.textContent = countLine(shown, listed);

      /* Filtering is an explicit act, so it re-frames even if the view was
         moved by hand. */
      if (refit && bounds.length) {
        userMoved = false;
        frame(bounds);
      }

      return shown;
    }

    function countLine(shown, listed) {
      return DM.count(shown) + ' on the map · ' + DM.count(listed) + ' listed';
    }

    /* --- the filter controls --- */

    function chip(label, withCount, pressed, onPick) {
      var button = el('button', {
        type: 'button',
        class: 'chip',
        'aria-pressed': pressed ? 'true' : 'false'
      }, [label, withCount ? el('span', { class: 'chip-count' }) : null]);

      button.addEventListener('click', function () { onPick(button); });
      return button;
    }

    function buildFilters(points) {
      /* Countries come from the data that arrived, so one appearing for the
         first time gets its button without anyone editing this file. */
      var names = [];
      points.forEach(function (p) {
        if (p.country && names.indexOf(p.country) === -1) names.push(p.country);
      });
      names.sort(function (a, b) { return a.localeCompare(b); });

      var statusButtons = [];
      var countryButtons = [];

      function countIn(country) {
        var n = 0;
        points.forEach(function (p) {
          if (country !== 'all' && p.country !== country) return;
          if (filter.status === 'flagged' && !p.flagged) return;
          n++;
        });
        return n;
      }

      /* The country counts depend on the status switch, so they are recomputed
         rather than frozen when the buttons are built. */
      function refreshCounts() {
        countryButtons.forEach(function (b) {
          var n = countIn(b.getAttribute('data-country'));
          var slot = b.querySelector('.chip-count');
          if (slot) slot.textContent = DM.count(n);
          b.disabled = n === 0;
        });
      }

      function pickStatus(value, button) {
        filter.status = value;
        statusButtons.forEach(function (b) {
          b.setAttribute('aria-pressed', b === button ? 'true' : 'false');
        });
        refreshCounts();
        applyFilter(true);
      }

      function pickCountry(value, button) {
        filter.country = value;
        countryButtons.forEach(function (b) {
          b.setAttribute('aria-pressed', b === button ? 'true' : 'false');
        });
        applyFilter(true);
      }

      statusButtons = [
        chip('All territories', false, true, function (b) { pickStatus('all', b); }),
        chip('Flagged only', false, false, function (b) { pickStatus('flagged', b); })
      ];

      countryButtons = [chip('All', true, true, function (b) { pickCountry('all', b); })];
      countryButtons[0].setAttribute('data-country', 'all');

      names.forEach(function (name) {
        var button = chip(name, true, false, function (b) { pickCountry(name, b); });
        button.setAttribute('data-country', name);
        countryButtons.push(button);
      });

      DM.replace(filterNode, [
        el('div', { class: 'filter-bar' }, [
          el('span', { class: 'filter-label', text: 'Show' }),
          el('div', { class: 'chips' }, statusButtons)
        ]),
        el('div', { class: 'filter-bar' }, [
          el('span', { class: 'filter-label', text: 'Country' }),
          el('div', { class: 'chips' }, countryButtons)
        ])
      ]);

      refreshCounts();
    }

    /* --- the list --- */

    function rowFrom(ev) {
      var node = ev.target;
      if (!node || !node.closest) return null;
      return node.closest('.explorer-row');
    }

    function buildList(points) {
      /* Every territory that lost forest, most hectares first. The flagged
         ones keep their place in that order and are marked instead, so the
         ordering stays the one the column header claims. */
      var rows = points.filter(function (p) { return p.lost_ha > 0; });
      rows.sort(function (a, b) { return b.lost_ha - a.lost_ha; });

      var fragment = document.createDocumentFragment();

      rows.forEach(function (point, index) {
        var name = DM.territoryName(point.name, point.territory_id);
        var ratio = DM.ratio(point.ratio);

        var row = el('a', {
          class: 'explorer-row' + (point.flagged ? ' is-flagged' : ''),
          href: 'territory.php?id=' + point.territory_id,
        }, [
          el('span', { class: 'ex-rank', text: String(index + 1) }),
          el('span', { class: 'ex-name' }, [
            name,
            ' ',
            el('span', { class: 'ex-country', text: point.country || DM.NO_DATA })
          ]),
          el('span', { class: 'ex-ha', text: DM.ha(point.lost_ha, false) }),
          el('span', {
            class: 'ex-ratio' + (ratio === null ? ' is-none' : ''),
            text: ratio === null ? 'no baseline' : ratio
          })
        ]);

        row.setAttribute('data-id', String(point.territory_id));
        byId[point.territory_id].row = row;
        fragment.appendChild(row);
      });

      /* Built off-document and inserted once, rather than two thousand
         appends into a live element. */
      DM.clear(listNode);
      listNode.appendChild(fragment);

      /* One listener for the whole list rather than one per row — and attached
         once for the life of the page, not once per draw. On history.php the
         list is rebuilt on every week change, and re-attaching here would
         leave a week's worth of duplicate handlers behind each time. They are
         on the container and read the row from the event, so they keep working
         over rows that did not exist when they were attached. */
      if (listWired) return;
      listWired = true;

      listNode.addEventListener('mouseover', function (ev) {
        var row = rowFrom(ev);
        setActive(row ? Number(row.getAttribute('data-id')) : null);
      });
      listNode.addEventListener('mouseleave', function () { setActive(null); });
      listNode.addEventListener('focusin', function (ev) {
        var row = rowFrom(ev);
        if (row) setActive(Number(row.getAttribute('data-id')));
      });
    }

    /* --- drawing --- */

    function onMarkerOver(ev)  { setActive(ev.target.dmId); }
    function onMarkerOut()     { setActive(null); }
    function onMarkerClick(ev) { setSelected(ev.target.dmId); }

    function draw(points) {
      var flaggedBounds = [];
      var allBounds = [];
      var countries = {};
      var flaggedCount = 0;

      points.forEach(function (point) {
        if (point.lat === null || point.lon === null) return;

        var marker = L.circleMarker([point.lat, point.lon], styleFor(point));
        marker.dmId = point.territory_id;
        marker.bindPopup(function () { return popupFor(point); });

        marker.on('mouseover', onMarkerOver);
        marker.on('mouseout', onMarkerOut);
        if (isExplorer) marker.on('click', onMarkerClick);

        var layer = layers[point.flagged ? 'flagged' : (point.lost_ha > 0 ? 'loss' : 'quiet')];
        layer.addLayer(marker);

        var entry = { point: point, marker: marker, layer: layer, row: null };
        entries.push(entry);
        byId[point.territory_id] = entry;

        allBounds.push([point.lat, point.lon]);
        if (point.flagged) {
          flaggedCount++;
          flaggedBounds.push([point.lat, point.lon]);
          if (point.country) countries[point.country] = true;
        }
      });

      if (isExplorer) buildList(points);
      if (filterNode) buildFilters(points);

      if (captionNode) {
        var countryCount = Object.keys(countries).length;
        captionNode.textContent = flaggedCount === 0
          ? 'No territory was flagged in this window'
          : DM.count(flaggedCount) + ' flagged territories across ' +
            countryCount + (countryCount === 1 ? ' country' : ' countries');
      }

      if (countNode) {
        countNode.textContent = countLine(
          entries.length,
          entries.filter(function (e) { return e.row !== null; }).length
        );
      }

      overlay.hidden = true;
      legend.hidden = only !== 'all';

      /* The exploration view opens on everything it is showing; the home page
         panel opens on the flagged territories, which are why it is there. */
      frame(isExplorer ? allBounds : flaggedBounds);
    }

    /* --- states --- */

    /* How many territories are watched, printed by partials.php on <body> from
       the one constant that holds it. Typed into this file it was a second
       copy that nobody would think to change when RAISG publishes a new set —
       and a loading line that names the wrong number is worse than one that
       names none, so a missing or unreadable attribute drops the figure rather
       than guessing at it. */
    function territoryCount() {
      var raw = document.body.getAttribute('data-territories');
      var n = raw === null ? NaN : Number(raw);
      return isFinite(n) && n > 0 ? DM.count(n) : null;
    }

    function showLoading() {
      var total = only === 'flagged' ? null : territoryCount();

      overlay.hidden = false;
      DM.replace(overlay, [
        el('div', { class: 'state' }, [
          el('span', { class: 'spinner' }),
          el('span', {
            class: 'map-status',
            text: only === 'flagged'
              ? 'Loading the flagged territories…'
              : (total === null
                  ? 'Loading every territory…'
                  : 'Loading ' + total + ' territories…')
          })
        ])
      ]);
    }

    function showError(message) {
      var retry = el('button', { type: 'button', text: 'Try again' });
      retry.addEventListener('click', load);

      DM.replace(overlay, [
        el('div', { class: 'state state-error' }, [
          el('div', { text: 'Could not load the map. ' + message }),
          retry
        ])
      ]);
    }

    /* Everything one draw put on the map, taken back off.
     *
     * A second draw without this would add a second set of markers to the same
     * layers and leave the first set's ids in byId — the map would accumulate
     * every week ever looked at, and the halo would still find territories
     * that are no longer shown. */
    function reset() {
      entries.forEach(function (entry) { entry.layer.removeLayer(entry.marker); });
      entries.length = 0;
      byId = {};
      activeId = null;
      selectedId = null;
      if (map.hasLayer(halo)) map.removeLayer(halo);
      if (listNode) DM.clear(listNode);
      /* The next frame() is a fresh fit, not a return to where the reader had
         dragged the previous week's map to. */
      userMoved = false;
      lastBounds = [];
    }

    function load() {
      showLoading();

      /* Neon suspends idle compute; the first request can take a few seconds. */
      var waking = setTimeout(function () {
        var status = overlay.querySelector('.map-status');
        if (status) status.textContent = 'Waking the database… this can take a few seconds.';
      }, 2500);

      /* data-window names a window_end. Absent — which is every page but
         history.php — the points come from `rankings`, the window being
         evaluated now. Present, they come from `rankings_history` for that
         week. api.php decides which table; this only passes the date on. */
      var url = 'api.php?r=points' + (only === 'flagged' ? '&only=flagged' : '');
      var chosen = shell.getAttribute('data-window');
      if (chosen) url += '&w=' + encodeURIComponent(chosen);

      DM.fetchJSON(url).then(function (points) {
        clearTimeout(waking);
        reset();
        draw(points);
      }).catch(function (err) {
        clearTimeout(waking);
        showError(err.message);
      });
    }

    /* Published alongside the highlight so a page that owns a week picker can
       move the map to another week without rebuilding the map. */
    shell.dmMap.setWindow = function (value) {
      shell.setAttribute('data-window', value || '');
      load();
    };

    /* The presence of data-window — empty or not — means this map belongs to a
       page that chooses the week, so it waits to be told which.
       Loading first and being corrected afterwards would spend a request on
       the wrong data and, worse, show the current window's points for a moment
       under a heading that names a past one. */
    if (shell.getAttribute('data-window') === null) {
      load();
    } else {
      showLoading();
    }
  }

  Array.prototype.forEach.call(
    document.querySelectorAll('[data-territory-map]'),
    createMap
  );
})();
