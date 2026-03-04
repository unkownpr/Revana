'use strict';

var id = {};
var tabLinks = {};
var contentDivs = {};

function template(lnk, vars)
{
	var options = {};
	if (vars) {
		options.method = 'POST';
		options.headers = { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' };
		options.body = vars;
	}
	fetch(lnk, options)
		.then(function(response) { return response.text(); })
		.then(function(data) {
			var con = document.getElementById('content');
			if (!con) return;
			con.innerHTML = data;

			// Execute inline/external scripts for dynamically loaded content.
			var scripts = con.querySelectorAll('script');
			for (var i = 0; i < scripts.length; i++) {
				var oldScript = scripts[i];
				var newScript = document.createElement('script');
				if (oldScript.src) {
					newScript.src = oldScript.src;
				}
				if (oldScript.type) {
					newScript.type = oldScript.type;
				}
				newScript.text = oldScript.text || oldScript.textContent || '';
				oldScript.parentNode.replaceChild(newScript, oldScript);
			}
		});
}

function chatWidget(currentUserId) {
	var csrfMeta = document.querySelector('meta[name="csrf-token"]');
	var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

	function parseJsonSafe(response) {
		return response.text().then(function(text) {
			try { return JSON.parse(text); } catch (e) { return null; }
		});
	}

	function getJson(url) {
		return fetch(url, {
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		}).then(parseJsonSafe);
	}

	function postForm(url, body) {
		var fullBody = body ? body + '&' : '';
		fullBody += 'csrf_token=' + encodeURIComponent(csrfToken || '');
		return fetch(url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'X-Requested-With': 'XMLHttpRequest'
			},
			body: fullBody
		}).then(parseJsonSafe);
	}

	return {
		currentUserId: parseInt(currentUserId || 0, 10),
		open: false,
		rooms: [],
		currentRoom: 1,
		messages: [],
		newMessage: '',
		unreadCount: 0,
		_pollUnread: null,
		_pollMessages: null,

		init: function() {
			this.checkUnread();
			this.loadRooms();
			var self = this;
			this._pollUnread = setInterval(function() { self.checkUnread(); }, 15000);
		},

		loadRooms: function() {
			var self = this;
			getJson('/chat/api/rooms')
				.then(function(data) {
					if (Array.isArray(data)) self.rooms = data;
				});
		},

		loadMessages: function(roomId) {
			this.currentRoom = roomId;
			var self = this;
			getJson('/chat/api/messages?room=' + roomId)
				.then(function(data) {
					if (Array.isArray(data)) {
						self.messages = data;
						self.scrollToBottom();
					}
				});
		},

		sendMessage: function() {
			if (!this.newMessage.trim()) return;
			var self = this;
			var body = 'room=' + this.currentRoom + '&message=' + encodeURIComponent(this.newMessage);
			this.newMessage = '';
			postForm('/chat/api/send', body)
			.then(function() {
				self.loadMessages(self.currentRoom);
			});
		},

		checkUnread: function() {
			var self = this;
			getJson('/chat/api/unread')
				.then(function(data) {
					if (data && typeof data.count !== 'undefined') {
						self.unreadCount = data.count || 0;
					}
				});
		},

		toggleChat: function() {
			this.open = !this.open;
			var self = this;
			if (this.open) {
				this.loadMessages(this.currentRoom);
				this.markRead();
				this._pollMessages = setInterval(function() {
					self.loadMessages(self.currentRoom);
				}, 5000);
			} else {
				if (this._pollMessages) {
					clearInterval(this._pollMessages);
					this._pollMessages = null;
				}
			}
		},

		markRead: function() {
			var self = this;
			postForm('/chat/api/mark-read', '')
				.then(function() { self.unreadCount = 0; });
		},

		scrollToBottom: function() {
			var self = this;
			setTimeout(function() {
				var el = self.$refs.chatMessages;
				if (el) el.scrollTop = el.scrollHeight;
			}, 50);
		},

		isOwn: function(msg) {
			return parseInt(msg && msg.sender_id ? msg.sender_id : 0, 10) === this.currentUserId;
		}
	};
}

function go(lnk)
{
	window.location.href = lnk;
}

function desc(town, player, population, alliance)
{
	var el = document.getElementById("descriptor");
	if (!el) return;
	el.innerHTML = "<table class='map-desc-table'><tr><td colspan='2'>" + town + "</td></tr><tr><td>Player</td><td>" + player + "</td></tr><tr><td>Population</td><td>" + population + "</td></tr><tr><td>Alliance</td><td>" + alliance + "</td></tr></table>";
}

if (typeof window.descClear !== 'function') {
	window.descClear = function() {
		if (typeof window.desc === 'function') {
			window.desc('-', '-', '-', '-');
		}
	};
}

function timer(data, lnk)
{
	var dat = document.getElementById(data);
	if (!dat) return;
	var time = (dat.innerHTML).split(":");
	var done = 0;
	if (time[2] > 0) time[2]--;
	else
	{
		time[2] = 59;
		if (time[1] > 0) time[1]--;
		else
		{
			time[1] = 59;
			if (time[0] > 0) time[0]--;
			else { clearTimeout(id[data]); window.location.href = lnk; done = 1; }
		}
	}
	if (!done)
	{
		dat.innerHTML = time[0] + ":" + time[1] + ":" + time[2];
		id[data] = setTimeout(function() { timer(data, lnk); }, 1000);
	}
}

function forgot()
{
	var usr = document.getElementById("name");
	if (!usr) return;
	go("forgot.php?name=" + usr.value);
}

function trade_options(type, subtype, data)
{
	var t = document.getElementById(type);
	var st = document.getElementById(subtype);
	if (!t || !st) return;
	if (t.value == 0) st.innerHTML = "<select name='" + subtype.substr(0, subtype.length - 1) + "'><option value='0'>Crop</option><option value='1'>Lumber</option><option value='2'>Stone</option><option value='3'>Iron</option><option value='4'>Gold</option></select>";
	else st.innerHTML = "<select name='" + subtype.substr(0, subtype.length - 1) + "'><option value='0'>" + data[0] + "</option><option value='1'>" + data[1] + "</option><option value='2'>" + data[2] + "</option><option value='3'>" + data[3] + "</option><option value='4'>" + data[4] + "</option><option value='5'>" + data[5] + "</option><option value='6'>" + data[6] + "</option><option value='7'>" + data[7] + "</option><option value='8'>" + data[8] + "</option><option value='9'>" + data[9] + "</option><option value='10'>" + data[10] + "</option></select>";
}

function showtext(building)
{
	var el = document.getElementById("label");
	if (!el) return;
	if (!building || building === '-') {
		el.style.display = 'none';
		el.innerHTML = '';
	} else {
		el.innerHTML = building;
		el.style.display = 'block';
	}
}

document.addEventListener('mousemove', function(e) {
	var el = document.getElementById("label");
	if (!el || el.style.display === 'none') return;
	var offset = 15;
	var x = e.clientX + offset;
	var y = e.clientY + offset;
	// Keep tooltip within viewport
	var rect = el.getBoundingClientRect();
	if (x + rect.width > window.innerWidth) x = e.clientX - rect.width - offset;
	if (y + rect.height > window.innerHeight) y = e.clientY - rect.height - offset;
	el.style.left = x + 'px';
	el.style.top = y + 'px';
});

function startres()
{
	var rates = [res_ph0/3600, res_ph1/3600, res_ph2/3600, res_ph3/3600, res_ph4/3600];
	var vals  = [res_start0,   res_start1,   res_start2,   res_start3,   res_start4];
	var lims  = [res_limit0,   res_limit1,   res_limit2,   res_limit3,   res_limit4];
	var prev  = vals.map(Math.floor);
	window.resFloat = vals; // expose live float values for tooltips
	var lastTs = performance.now();

	function frame(ts) {
		var dt = Math.min((ts - lastTs) / 1000, 0.5); // cap at 0.5s to avoid jumps on tab switch
		lastTs = ts;

		for (var i = 0; i < 5; i++) {
			vals[i] = Math.min(vals[i] + rates[i] * dt, lims[i]);
			var el = document.getElementById('res' + i);
			if (!el) continue;
			var v = Math.floor(vals[i]);
			if (v !== prev[i]) {
				if (v > prev[i] && rates[i] > 0) _resFlash(el, '+' + (v - prev[i]));
				prev[i] = v;
			}
			el.textContent = v;
		}

		requestAnimationFrame(frame);
	}

	requestAnimationFrame(frame);
}

function _resFlash(el, label) {
	el.classList.remove('res-tick');
	void el.offsetWidth; // force reflow to restart animation
	el.classList.add('res-tick');

	var wrap = el.parentElement;
	if (!wrap) return;
	var f = document.createElement('span');
	f.className = 'res-float';
	f.textContent = label;
	wrap.appendChild(f);
	setTimeout(function() { if (f.parentNode) f.parentNode.removeChild(f); }, 900);
}

function inittabs() {
	var tabList = document.getElementById('tabs');
	if (!tabList) return;
	var tabListItems = tabList.childNodes;
	for (var i = 0; i < tabListItems.length; i++) {
		if (tabListItems[i].nodeName == "LI") {
			var tabLink = getFirstChildWithTagName(tabListItems[i], 'A');
			var tabId = getHash(tabLink.getAttribute('href'));
			tabLinks[tabId] = tabLink;
			contentDivs[tabId] = document.getElementById(tabId);
		}
	}

	var j = 0;
	for (var tabId in tabLinks) {
		tabLinks[tabId].onclick = showTab;
		tabLinks[tabId].onfocus = function() { this.blur(); };
		if (j == 0) tabLinks[tabId].className = 'selected';
		j++;
	}

	var k = 0;
	for (var tabId in contentDivs) {
		if (k != 0) contentDivs[tabId].className = 'tabContent hide';
		k++;
	}
}

function showTab() {
	var selectedId = getHash(this.getAttribute('href'));

	for (var tabId in contentDivs) {
		if (tabId == selectedId) {
			tabLinks[tabId].className = 'selected';
			contentDivs[tabId].className = 'tabContent';
		} else {
			tabLinks[tabId].className = '';
			contentDivs[tabId].className = 'tabContent hide';
		}
	}

	return false;
}

function getFirstChildWithTagName(element, tagName) {
	for (var i = 0; i < element.childNodes.length; i++) {
		if (element.childNodes[i].nodeName == tagName) return element.childNodes[i];
	}
}

function getHash(url) {
	var hashPos = url.lastIndexOf('#');
	return url.substring(hashPos + 1);
}

function xmenu(uid, tid, utid)
{
	var el = document.getElementById("xmenu");
	if (!el) return;
	el.innerHTML = "<a class='q_link' href='/profile/" + uid + "'>view</a>";
	if (utid) el.innerHTML += " | <a class='q_link' href='/messages/compose?to=" + uid + "'>write</a> | <a class='q_link' href='/town/" + utid + "/dispatch?target=" + tid + "'>dispatch</a>";
}

function map()
{
	var x = document.getElementById("x");
	var y = document.getElementById("y");
	if (!x || !y) return;
	window.location.href = '/map?x=' + x.value + '&y=' + y.value;
}

if (typeof window.loadMap !== 'function') {
	window.loadMap = function(x, y) {
		var nx = parseInt(x, 10);
		var ny = parseInt(y, 10);
		if (isNaN(nx)) nx = 0;
		if (isNaN(ny)) ny = 0;
		window.location.href = '/map?x=' + nx + '&y=' + ny;
	};
}

function act_forum(town)
{
	var vars = "a=" + document.getElementById("f_a").value + "&id=" + document.getElementById("f_id").value + "&parent=" + document.getElementById("f_parent").value + "&name=" + document.getElementById("f_name").value + "&desc=" + document.getElementById("f_desc").value;
	template("forums_.php?town=" + town, vars);
}

function act_thread(town, forum)
{
	var vars = "a=" + document.getElementById("t_a").value + "&id=" + document.getElementById("t_id").value + "&name=" + document.getElementById("t_name").value + "&desc=" + document.getElementById("t_desc").value + "&content=" + document.getElementById("t_content").value;
	template("thread_.php?town=" + town + "&forum=" + forum, vars);
}

function act_post(town, thread)
{
	var vars = "a=" + document.getElementById("p_a").value + "&id=" + document.getElementById("p_id").value + "&desc=" + document.getElementById("p_desc").value + "&content=" + document.getElementById("p_content").value;
	template("posts_.php?town=" + town + "&thread=" + thread, vars);
}

function townEditor() {
	var cfg = window.townEditorConfig || {};
	return {
		editMode: false,
		townId: cfg.townId || 0,
		csrfToken: cfg.csrfToken || '',
		imgBase: cfg.imgBase || '',
		positions: JSON.parse(JSON.stringify(cfg.initialPositions || {})),
		background: cfg.initialBackground || 'back.png',
		availableBackgrounds: cfg.availableBackgrounds || [],
		premiumBackgroundMeta: cfg.premiumBackgroundMeta || {},
		backgroundBuyUrl: cfg.backgroundBuyUrl || '',
		backgroundMasks: cfg.backgroundMasks || {},
		buildingImages: cfg.buildingImages || {},
		buildingNames: cfg.buildingNames || {},
		hoveredBuilding: -1,
		dragging: -1,
		dragOffsetX: 0,
		dragOffsetY: 0,
		wasDragged: false,
		panX: 0,
		panY: 0,
		panMaxX: 0,
		panMaxY: 0,
		panning: false,
		panStartTouchX: 0,
		panStartTouchY: 0,
		panStartX: 0,
		panStartY: 0,
		panMoved: false,
		panSuppressClickUntil: 0,
		_onDrag: null,
		_onDragEnd: null,
		_lastSuccessToastAt: 0,
		_onPanResize: null,

		init: function() {
			var self = this;
			this.updatePanBounds();
			this._onPanResize = function() { self.updatePanBounds(); };
			window.addEventListener('resize', this._onPanResize);
		},

		isPanEnabled: function() {
			if (this.editMode) return false;
			return window.matchMedia('(max-width: 980px)').matches;
		},

		getTownScale: function() {
			var node = document.querySelector('.node');
			if (!node) return 1;
			var raw = getComputedStyle(node).getPropertyValue('--town-scale');
			var n = parseFloat(raw);
			return isNaN(n) || n <= 0 ? 1 : n;
		},

		getEffectiveScale: function() {
			var base = this.getTownScale();
			if (this.isPanEnabled()) {
				// Mobile pan needs slight zoom so user can drag around the scene.
				return base * 1.22;
			}
			return base;
		},

		updatePanBounds: function() {
			var view = this.$refs && this.$refs.townView ? this.$refs.townView : document.querySelector('.town-view');
			if (!view) return;
			var scale = this.getEffectiveScale();
			var scaledW = 640 * scale;
			var scaledH = 372 * scale;
			this.panMaxX = Math.max(0, Math.round(scaledW - view.clientWidth));
			this.panMaxY = Math.max(0, Math.round(scaledH - view.clientHeight));
			this.panX = Math.max(-this.panMaxX, Math.min(0, this.panX));
			this.panY = Math.max(-this.panMaxY, Math.min(0, this.panY));
		},

		townInnerStyle: function() {
			return 'line-height:0;font-size:0;overflow:visible;transform:translate(' + this.panX + 'px,' + this.panY + 'px) scale(' + this.getEffectiveScale().toFixed(4) + ');transform-origin:top left;';
		},

		startPan: function(ev) {
			if (!this.isPanEnabled()) return;
			if (!ev.touches || ev.touches.length !== 1) return;
			this.updatePanBounds();
			this.panning = true;
			this.panMoved = false;
			this.panStartTouchX = ev.touches[0].clientX;
			this.panStartTouchY = ev.touches[0].clientY;
			this.panStartX = this.panX;
			this.panStartY = this.panY;
		},

		onPan: function(ev) {
			if (!this.panning || !this.isPanEnabled()) return;
			if (!ev.touches || ev.touches.length !== 1) return;
			var dx = ev.touches[0].clientX - this.panStartTouchX;
			var dy = ev.touches[0].clientY - this.panStartTouchY;
			if (Math.abs(dx) > 3 || Math.abs(dy) > 3) this.panMoved = true;
			this.panX = Math.max(-this.panMaxX, Math.min(0, Math.round(this.panStartX + dx)));
			this.panY = Math.max(-this.panMaxY, Math.min(0, Math.round(this.panStartY + dy)));
		},

		endPan: function() {
			if (this.panMoved) {
				this.panSuppressClickUntil = Date.now() + 220;
			}
			this.panning = false;
			this.panMoved = false;
		},

		toggleEdit: function() {
			var wasEditMode = this.editMode;
			this.editMode = !this.editMode;
			if (this.editMode) {
				this.panX = 0;
				this.panY = 0;
			}
			if (wasEditMode && !this.editMode) {
				this.persistLayout();
			}
			this.updatePanBounds();
			if (!this.editMode) {
				this.hoveredBuilding = -1;
				showtext('-');
			}
		},

		postLayout: function(payload) {
			if (this.csrfToken && !payload.csrf_token) {
				payload.csrf_token = this.csrfToken;
			}
			return fetch('/town/' + this.townId + '/layout', {
				method: 'POST',
				headers: {'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken},
				body: JSON.stringify(payload)
			}).then(function(response) {
				if (!response.ok) {
					throw new Error('http_' + response.status);
				}
				return response.json();
			}).then(function(data) {
				if (!data || data.ok !== true) {
					throw new Error((data && data.error) ? data.error : 'save_failed');
				}
				return data;
			});
		},

		showLayoutToast: function(message, kind) {
			var toastKind = kind === 'success' ? 'success' : 'error';
			var existing = document.getElementById('layout-save-toast');
			if (existing) existing.remove();

			var toast = document.createElement('div');
			toast.id = 'layout-save-toast';
			toast.textContent = message || 'Kaydedilemedi';
			toast.style.position = 'fixed';
			toast.style.right = '18px';
			toast.style.bottom = '18px';
			toast.style.zIndex = '99999';
			toast.style.padding = '10px 12px';
			toast.style.borderRadius = '8px';
			toast.style.border = toastKind === 'success'
				? '1px solid rgba(74,160,91,.65)'
				: '1px solid rgba(214,76,56,.65)';
			toast.style.background = toastKind === 'success'
				? 'rgba(20,56,28,.96)'
				: 'rgba(56,20,16,.96)';
			toast.style.color = toastKind === 'success'
				? '#dff7e4'
				: '#ffe2dc';
			toast.style.fontSize = '13px';
			toast.style.boxShadow = '0 8px 18px rgba(0,0,0,.35)';
			toast.style.opacity = '0';
			toast.style.transform = 'translateY(6px)';
			toast.style.transition = 'opacity .18s ease, transform .18s ease';

			document.body.appendChild(toast);
			requestAnimationFrame(function() {
				toast.style.opacity = '1';
				toast.style.transform = 'translateY(0)';
			});

			setTimeout(function() {
				toast.style.opacity = '0';
				toast.style.transform = 'translateY(6px)';
				setTimeout(function() {
					if (toast.parentNode) toast.parentNode.removeChild(toast);
				}, 220);
			}, 2200);
		},

		showLayoutSavedToast: function() {
			var now = Date.now();
			if (now - this._lastSuccessToastAt < 1200) return;
			this._lastSuccessToastAt = now;
			this.showLayoutToast('Kaydedildi', 'success');
		},

		persistLayout: function() {
			var payload = {
				background: this.background,
				positions: this.positions
			};
			this.postLayout(payload)
				.then(this.showLayoutSavedToast.bind(this))
				.catch(this.showLayoutToast.bind(this, 'Kaydedilemedi', 'error'));
		},

		currentBlockedZones: function() {
			var all = this.backgroundMasks[this.background] || [];
			// Only none and water zones block building placement / show red overlay
			return all.filter(function(z) {
				var fx = (z && z.fx) ? z.fx : 'none';
				return fx === 'none' || fx === 'water';
			});
		},

		zoneVisualClass: function(zone) {
			var t = (zone && zone.t) ? zone.t : 'rect';
			if (t === 'rect') return '';
			if (t === 'poly') return 'blocked-zone--poly';
			return 'blocked-zone--circle';
		},

		zoneVisualStyle: function(zone) {
			if (!zone) return '';
			var t = zone.t || 'rect';
			if (t === 'rect') {
				return 'left:' + (zone.x || 0) + 'px;top:' + (zone.y || 0) + 'px;width:' + (zone.w || 0) + 'px;height:' + (zone.h || 0) + 'px;';
			}
			if (t === 'poly' && Array.isArray(zone.points) && zone.points.length > 2) {
				var minX = 639, minY = 371, maxX = 0, maxY = 0;
				for (var i = 0; i < zone.points.length; i++) {
					var px = parseInt(zone.points[i].x || 0, 10);
					var py = parseInt(zone.points[i].y || 0, 10);
					if (px < minX) minX = px;
					if (py < minY) minY = py;
					if (px > maxX) maxX = px;
					if (py > maxY) maxY = py;
				}
				var w = Math.max(1, maxX - minX);
				var h = Math.max(1, maxY - minY);
				var clip = [];
				for (var k = 0; k < zone.points.length; k++) {
					var rx = (((parseInt(zone.points[k].x || 0, 10) - minX) / w) * 100).toFixed(2);
					var ry = (((parseInt(zone.points[k].y || 0, 10) - minY) / h) * 100).toFixed(2);
					clip.push(rx + '% ' + ry + '%');
				}
				return 'left:' + minX + 'px;top:' + minY + 'px;width:' + w + 'px;height:' + h + 'px;clip-path:polygon(' + clip.join(',') + ');';
			}
			var cx = parseInt(zone.cx || 0, 10);
			var cy = parseInt(zone.cy || 0, 10);
			var r = parseInt(zone.r || 0, 10);
			var size = r * 2;
			return 'left:' + (cx - r) + 'px;top:' + (cy - r) + 'px;width:' + size + 'px;height:' + size + 'px;';
		},

		pointInPolygon: function(px, py, points) {
			var inside = false;
			for (var i = 0, j = points.length - 1; i < points.length; j = i++) {
				var xi = parseInt(points[i].x || 0, 10);
				var yi = parseInt(points[i].y || 0, 10);
				var xj = parseInt(points[j].x || 0, 10);
				var yj = parseInt(points[j].y || 0, 10);
				var intersect = ((yi > py) !== (yj > py)) && (px < ((xj - xi) * (py - yi)) / ((yj - yi) || 1e-6) + xi);
				if (intersect) inside = !inside;
			}
			return inside;
		},

		segmentIntersects: function(ax, ay, bx, by, cx, cy, dx, dy) {
			var ccw = function(px, py, qx, qy, rx, ry) {
				return (ry - py) * (qx - px) > (qy - py) * (rx - px);
			};
			return ccw(ax, ay, cx, cy, dx, dy) !== ccw(bx, by, cx, cy, dx, dy)
				&& ccw(ax, ay, bx, by, cx, cy) !== ccw(ax, ay, bx, by, dx, dy);
		},

		polygonIntersectsRect: function(points, left, top, right, bottom) {
			var corners = [
				{x: left, y: top},
				{x: right, y: top},
				{x: right, y: bottom},
				{x: left, y: bottom},
				{x: (left + right) / 2, y: (top + bottom) / 2}
			];
			for (var i = 0; i < corners.length; i++) {
				if (this.pointInPolygon(corners[i].x, corners[i].y, points)) return true;
			}
			for (var p = 0; p < points.length; p++) {
				var px = parseInt(points[p].x || 0, 10);
				var py = parseInt(points[p].y || 0, 10);
				if (px >= left && px <= right && py >= top && py <= bottom) return true;
			}
			for (var a = 0; a < points.length; a++) {
				var b = (a + 1) % points.length;
				var ax = parseInt(points[a].x || 0, 10);
				var ay = parseInt(points[a].y || 0, 10);
				var bx = parseInt(points[b].x || 0, 10);
				var by = parseInt(points[b].y || 0, 10);
				if (this.segmentIntersects(ax, ay, bx, by, left, top, right, top)) return true;
				if (this.segmentIntersects(ax, ay, bx, by, right, top, right, bottom)) return true;
				if (this.segmentIntersects(ax, ay, bx, by, right, bottom, left, bottom)) return true;
				if (this.segmentIntersects(ax, ay, bx, by, left, bottom, left, top)) return true;
			}
			return false;
		},

		getBuildingSize: function(b) {
			var img = document.querySelector('.town-building[data-b="' + b + '"] img');
			if (!img) return { w: 64, h: 64 };
			return {
				w: Math.max(1, Math.round(img.offsetWidth || img.width || 64)),
				h: Math.max(1, Math.round(img.offsetHeight || img.height || 64))
			};
		},

		isBlockedAt: function(x, y, b) {
			var zones = this.currentBlockedZones();
			if (!zones || !zones.length) return false;

			var size = this.getBuildingSize(b);
			var left = x;
			var top = y;
			var right = x + size.w;
			var bottom = y + size.h;

			for (var i = 0; i < zones.length; i++) {
				var z = zones[i];
				var t = z && z.t ? z.t : 'rect';
				if (t === 'rect') {
					var zLeft = parseInt(z.x || 0, 10);
					var zTop = parseInt(z.y || 0, 10);
					var zRight = zLeft + parseInt(z.w || 0, 10);
					var zBottom = zTop + parseInt(z.h || 0, 10);
					var overlap = left < zRight && right > zLeft && top < zBottom && bottom > zTop;
					if (overlap) return true;
					continue;
				}
				if (t === 'circle' || t === 'brush') {
					var cx = parseInt(z.cx || 0, 10);
					var cy = parseInt(z.cy || 0, 10);
					var r = parseInt(z.r || 0, 10);
					var nearestX = Math.max(left, Math.min(cx, right));
					var nearestY = Math.max(top, Math.min(cy, bottom));
					var dx = cx - nearestX;
					var dy = cy - nearestY;
					if ((dx * dx) + (dy * dy) <= (r * r)) return true;
					continue;
				}
				if (t === 'poly' && Array.isArray(z.points) && z.points.length > 2) {
					if (this.polygonIntersectsRect(z.points, left, top, right, bottom)) return true;
				}
			}

			return false;
		},

		getBuildingImg: function(b) {
			var imgs = this.buildingImages[b];
			if (!imgs) return '';
			return imgs.normal;
		},

		hoverBuilding: function(b) {
			this.hoveredBuilding = b;
			var name = this.buildingNames[b] || '';
			showtext(name);
		},

		unhoverBuilding: function(b) {
			if (this.hoveredBuilding === b) {
				this.hoveredBuilding = -1;
				showtext('-');
			}
		},

		startDrag: function(ev, b) {
			if (!this.editMode) return;
			ev.preventDefault();
			this.dragging = b;
			this.wasDragged = false;

			var viewEl = ev.target.closest('.town-view');
			var viewRect = viewEl.getBoundingClientRect();
			this.dragOffsetX = ev.clientX - viewRect.left - this.positions[b].x;
			this.dragOffsetY = ev.clientY - viewRect.top - this.positions[b].y;

			var self = this;
			this._onDrag = function(e) { self.onDrag(e); };
			this._onDragEnd = function(e) { self.endDrag(e); };
			document.addEventListener('mousemove', this._onDrag);
			document.addEventListener('mouseup', this._onDragEnd);
		},

		onDrag: function(ev) {
			if (this.dragging < 0) return;
			this.wasDragged = true;
			var b = this.dragging;

			var viewEl = document.querySelector('.town-view');
			var viewRect = viewEl.getBoundingClientRect();

			var x = ev.clientX - viewRect.left - this.dragOffsetX;
			var y = ev.clientY - viewRect.top - this.dragOffsetY;

			// Clamp to canvas bounds
			x = Math.max(0, Math.min(565, Math.round(x)));
			y = Math.max(0, Math.min(272, Math.round(y)));
			if (!this.isBlockedAt(x, y, b)) {
				this.positions[b] = {x: x, y: y};
			}
		},

		endDrag: function(ev) {
			document.removeEventListener('mousemove', this._onDrag);
			document.removeEventListener('mouseup', this._onDragEnd);

			if (this.dragging >= 0 && this.wasDragged) {
				this.savePosition(this.dragging);
			}
			this.dragging = -1;
			this.wasDragged = false;
		},

		savePosition: function(b) {
			var pos = this.positions[b];
			var body = {positions: {}};
			body.positions[b] = {x: pos.x, y: pos.y};
			this.postLayout(body)
				.then(this.showLayoutSavedToast.bind(this))
				.catch(this.showLayoutToast.bind(this, 'Kaydedilemedi', 'error'));
		},

		changeBackground: function(bg) {
			var self = this;
			if (this.isBackgroundLocked(bg)) {
				var price = this.getBackgroundPrice(bg);
				var ok = window.confirm('Bu arka plan premium. ' + price + ' elmas ile satın almak ister misin?');
				if (!ok) return;
				this.buyPremiumBackground(bg)
					.then(function() {
						var meta = self.premiumBackgroundMeta[bg] || {};
						meta.owned = 1;
						self.premiumBackgroundMeta[bg] = meta;
						self.background = bg;
						return self.postLayout({background: bg});
					})
					.then(self.showLayoutSavedToast.bind(self))
					.catch(function(err) {
						var msg = (err && err.message) ? err.message : 'Satın alma başarısız';
						self.showLayoutToast(msg, 'error');
					});
				return;
			}
			this.background = bg;
			this.postLayout({background: bg})
				.then(this.showLayoutSavedToast.bind(this))
				.catch(this.showLayoutToast.bind(this, 'Kaydedilemedi', 'error'));
		},

		isBackgroundLocked: function(bg) {
			var meta = this.premiumBackgroundMeta[bg];
			if (!meta) return false;
			return parseInt(meta.is_premium || 0, 10) === 1 && parseInt(meta.owned || 0, 10) !== 1;
		},

		getBackgroundPrice: function(bg) {
			var meta = this.premiumBackgroundMeta[bg];
			if (!meta) return 0;
			return parseInt(meta.price_credits || 0, 10) || 0;
		},

		buyPremiumBackground: function(bg) {
			var url = this.backgroundBuyUrl || ('/town/' + this.townId + '/background/buy');
			return fetch(url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-CSRF-Token': this.csrfToken
				},
				body: JSON.stringify({
					background: bg,
					csrf_token: this.csrfToken
				})
			}).then(function(response) {
				if (!response.ok) {
					throw new Error('Satın alma başarısız');
				}
				return response.json();
			}).then(function(data) {
				if (!data || data.ok !== true) {
					throw new Error((data && data.error) ? data.error : 'Satın alma başarısız');
				}
				return data;
			});
		},

		/* ── Building Quick-Action Popup ────────────────────────── */
		buildingLevels: cfg.buildingLevels || {},
		buildingPopup: null,   /* { bId, loading, data } or null */

		showBuildingPopup: function(event) {
			if (this.editMode) return;
			if (Date.now() < this.panSuppressClickUntil) return;
			event.preventDefault();
			event.stopPropagation();

			/* Extract building ID from parent .town-building[data-b] */
			var bEl = event.currentTarget.closest('.town-building');
			var b = bEl ? parseInt(bEl.getAttribute('data-b'), 10) : -1;
			if (isNaN(b) || b < 0) return;

			/* Toggle off if same building clicked again */
			if (this.buildingPopup && this.buildingPopup.bId === b) {
				this.buildingPopup = null;
				return;
			}

			this.buildingPopup = { bId: b, loading: true, data: null };

			var self = this;
			fetch('/town/' + this.townId + '/building/' + b + '/quick')
				.then(function(r) {
					if (!r.ok) throw new Error('HTTP ' + r.status);
					return r.json();
				})
				.then(function(d) {
					if (self.buildingPopup && self.buildingPopup.bId === b) {
						self.buildingPopup.data = d;
						self.buildingPopup.loading = false;
					}
				})
				.catch(function() {
					if (self.buildingPopup && self.buildingPopup.bId === b) {
						var fbName = self.buildingNames[b] || '';
						self.buildingPopup = {
							bId: b,
							loading: false,
							data: {
								ok: true,
								building: {
									type: b,
									name: fbName,
									description: '',
									level: self.buildingLevels[b] || 0,
									max_level: 10,
									category: 'special'
								},
								upgrade: {
									can_upgrade: false,
									already_queued: false,
									at_max: true,
									cost: { crop: 0, lumber: 0, stone: 0, iron: 0, gold: 0, time: '0:00' }
								},
								trainable_units: [],
								forgeable_weapons: [],
								can_train: false,
								can_forge: false,
								can_upgrade_units: false,
								resources: { crop: 0, lumber: 0, stone: 0, iron: 0, gold: 0 }
							}
						};
					}
				});
		},

		closeBuildingPopup: function() {
			this.buildingPopup = null;
		}
	};
}

function notificationWidget() {
	var csrfMeta = document.querySelector('meta[name="csrf-token"]');
	var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

	function getJson(url) {
		return fetch(url).then(function(r) {
			return r.text().then(function(t) {
				try { return JSON.parse(t); } catch(e) { return null; }
			});
		});
	}

	function postJson(url, data) {
		var body = Object.keys(data).map(function(k) {
			return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
		}).join('&');
		return fetch(url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
			body: body
		}).then(function(r) {
			return r.text().then(function(t) {
				try { return JSON.parse(t); } catch(e) { return null; }
			});
		});
	}

	return {
		open: false,
		count: 0,
		notifications: [],
		_poll: null,

		init: function() {
			this.loadNotifications();
			var self = this;
			this._poll = setInterval(function() { self.loadNotifications(); }, 30000);
		},

		loadNotifications: function() {
			var self = this;
			getJson('/api/notifications').then(function(data) {
				if (!data) return;
				self.count = data.count || 0;
				self.notifications = data.notifications || [];
				// Update badge
				var badge = document.getElementById('notif-badge');
				if (badge) {
					badge.textContent = self.count > 0 ? self.count : '';
					badge.style.display = self.count > 0 ? '' : 'none';
				}
			});
		},

		toggle: function() {
			this.open = !this.open;
			if (this.open && this.count > 0) {
				var self = this;
				postJson('/api/notifications/read', { csrf: csrfToken }).then(function() {
					self.count = 0;
					self.notifications.forEach(function(n) { n._read = true; });
					var badge = document.getElementById('notif-badge');
					if (badge) { badge.style.display = 'none'; }
				});
			}
		},

		close: function() {
			this.open = false;
		}
	};
}
