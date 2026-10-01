/**
 * SelectorTester
 *
 * Loaded as a classic script by Process::init(), in <head>, before UIkit and before the DOM exists:
 * UIkit is touched only inside handlers, the form is wired on DOMContentLoaded.
 *
 * <selector-tester-results> renders into light DOM so admin theme (UIkit) styles apply. It only
 * displays and reports clicks; requests, "get more" and the modal live in the controller below.
 *
 * @author AT
 */
(function() {
	var cfg = window.ProcessWire && ProcessWire.config && ProcessWire.config.SelectorTester
	if(!cfg || !window.customElements || !window.fetch) return

	var labels = cfg.labels || {}
	var collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' })

	var columns = [
		{ key: 'id', num: true },
		{ key: 'title' },
		{ key: 'name' },
		{ key: 'template' },
		{ key: 'status', sortKey: '_status' },
		{ key: 'created', nowrap: true },
		{ key: 'modified', nowrap: true },
		{ key: 'createdBy' },
		{ key: 'modifiedBy' },
		{ key: 'path' },
		{ key: 'links', label: '', sortable: false }
	]

	function esc(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;')
	}

	function fmt(str, a, b) {
		return String(str || '').replace('%1$s', a).replace('%2$s', b).replace('%s', a)
	}

	function num(n) {
		return Number(n || 0).toLocaleString()
	}

	class SelectorTesterResults extends HTMLElement {

		constructor() {
			super()
			this.rows = []
			this.ids = new Set()
			this.sortKey = ''
			this.sortDir = 1
			this.filter = ''
			this.filterText = ''
			this.meta = null
			this.filterTimer = 0
			this.wired = false
		}

		connectedCallback() {
			if(this.wired) return
			this.wired = true
			this.addEventListener('click', this.onClick.bind(this))
			this.addEventListener('keydown', this.onKeydown.bind(this))
			this.addEventListener('input', this.onInput.bind(this))
		}

		get nextStart() {
			return this.meta ? this.meta.start + this.meta.count : 0
		}

		setData(data, append) {
			var rows = Array.isArray(data.rows) ? data.rows : []
			if(!append) {
				this.rows = []
				this.ids = new Set()
				this.sortKey = ''
				this.sortDir = 1
				this.filter = ''
				this.filterText = ''
			}
			for(var i = 0; i < rows.length; i++) {
				var row = rows[i]
				//a page saved between two chunks can move across the chunk border: show it once
				if(this.ids.has(row.id)) continue
				this.ids.add(row.id)
				row._status = (row.statusNames || []).join(' ')
				row._s = [row.id, row.title, row.name, row.template, row._status, row.created, row.modified,
					row.createdBy, row.modifiedBy, row.path].join('\n').toLowerCase()
				this.rows.push(row)
			}
			this.meta = data
			this.render()
		}

		showError(message) {
			var box = this.querySelector('.st-error')
			if(!box) {
				box = document.createElement('div')
				box.className = 'st-error'
				this.insertBefore(box, this.firstChild)
			}
			box.innerHTML = message ? '<div class="uk-alert uk-alert-danger" role="alert">' + esc(message) + '</div>' : ''
		}

		setBusy(busy) {
			this.setAttribute('aria-busy', busy ? 'true' : 'false')
			var more = this.querySelector('[data-more]')
			if(more) more.disabled = !!busy
		}

		render() {
			var meta = this.meta
			var self = this
			var head = columns.map(function(col) {
				var label = col.label != null ? col.label : (labels[col.key] || col.key)
				if(col.sortable === false) return '<th>' + esc(label) + '</th>'
				return '<th data-key="' + col.key + '" tabindex="0" aria-sort="' + self.ariaSort(col.key) + '">' + esc(label) + '</th>'
			}).join('')

			this.innerHTML =
				'<div class="st-error"></div>' +
				'<div class="st-toolbar">' +
					'<strong class="st-count"></strong>' +
					'<input type="search" class="uk-input uk-form-small st-filter" placeholder="' + esc(labels.filter) + '" aria-label="' + esc(labels.filter) + '" value="' + esc(this.filterText) + '">' +
					'<code class="st-selector">' + esc(meta.selector) + '</code>' +
					'<span class="uk-text-muted uk-text-small">' + esc(meta.ms) + ' ms</span>' +
				'</div>' +
				'<div class="uk-overflow-auto">' +
					'<table class="uk-table uk-table-small uk-table-divider uk-table-hover uk-table-striped st-table">' +
						'<thead><tr>' + head + '</tr></thead><tbody></tbody>' +
					'</table>' +
				'</div>' +
				'<p class="uk-text-muted st-empty" hidden></p>' +
				(meta.hasMore ? '<button type="button" class="ui-button uk-button uk-button-default st-more" data-more>' + esc(labels.more) + '</button>' : '')

			this.renderRows()
		}

		renderRows() {
			var tbody = this.querySelector('tbody')
			if(!tbody) return
			var filter = this.filter
			var view = filter ? this.rows.filter(function(row) { return row._s.indexOf(filter) !== -1 }) : this.rows

			if(this.sortKey) {
				var col = columns.filter(function(c) { return c.key === this.sortKey }, this)[0]
				var key = col.sortKey || col.key
				var dir = this.sortDir
				//Array#sort is stable: equal values keep the server order
				view = view.slice().sort(col.num
					? function(a, b) { return (a[key] - b[key]) * dir }
					: function(a, b) { return collator.compare(String(a[key]), String(b[key])) * dir })
			}

			var root = cfg.rootUrl || ''
			var edit = cfg.editUrl || ''
			var html = new Array(view.length)
			for(var i = 0; i < view.length; i++) {
				var r = view[i]
				html[i] = '<tr data-id="' + r.id + '" tabindex="0">' +
					'<td>' + r.id + '</td>' +
					'<td>' + esc(r.title) + '</td>' +
					'<td>' + esc(r.name) + '</td>' +
					'<td>' + esc(r.template) + '</td>' +
					'<td>' + esc(r._status) + '</td>' +
					'<td class="uk-text-nowrap">' + esc(r.created) + '</td>' +
					'<td class="uk-text-nowrap">' + esc(r.modified) + '</td>' +
					'<td>' + esc(r.createdBy) + '</td>' +
					'<td>' + esc(r.modifiedBy) + '</td>' +
					'<td class="st-path">' + esc(r.path) + '</td>' +
					'<td class="uk-text-nowrap">' +
						(r.path ? '<a href="' + esc(root + r.path) + '" target="_blank" rel="noopener">' + esc(labels.view) + '</a> ' : '') +
						'<a href="' + esc(edit + r.id) + '" target="_blank">' + esc(labels.edit) + '</a>' +
					'</td>' +
				'</tr>'
			}
			tbody.innerHTML = html.join('')

			var count = this.querySelector('.st-count')
			if(count) {
				count.textContent = fmt(labels.matches, num(this.rows.length), num(this.meta.total)) +
					(filter ? ' · ' + fmt(labels.shown, num(view.length)) : '')
			}
			var empty = this.querySelector('.st-empty')
			if(empty) {
				empty.hidden = view.length > 0
				empty.textContent = this.rows.length ? labels.noneFiltered : labels.none
			}
		}

		sortBy(key) {
			if(this.sortKey === key) {
				this.sortDir = -this.sortDir
			} else {
				this.sortKey = key
				this.sortDir = 1
			}
			var self = this
			this.querySelectorAll('th[data-key]').forEach(function(th) {
				th.setAttribute('aria-sort', self.ariaSort(th.getAttribute('data-key')))
			})
			this.renderRows()
		}

		ariaSort(key) {
			if(key !== this.sortKey) return 'none'
			return this.sortDir > 0 ? 'ascending' : 'descending'
		}

		emit(name, detail) {
			this.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: detail || {} }))
		}

		onClick(e) {
			var target = e.target
			if(!(target instanceof Element)) return
			//view/edit links navigate natively and must not open the modal too
			if(target.closest('a')) return
			var th = target.closest('th[data-key]')
			if(th) return this.sortBy(th.getAttribute('data-key'))
			if(target.closest('[data-more]')) return this.emit('selectortester:more')
			var tr = target.closest('tbody tr[data-id]')
			if(!tr) return
			//selecting text in a row is copying, not asking for details
			if(String(window.getSelection ? window.getSelection() : '').length) return
			this.emit('selectortester:detail', { id: Number(tr.getAttribute('data-id')) })
		}

		onKeydown(e) {
			var target = e.target
			if(!(target instanceof Element)) return
			if(target.matches('.st-filter') && e.key === 'Escape' && target.value) {
				target.value = ''
				this.applyFilter('')
				return
			}
			if(e.key !== 'Enter' && e.key !== ' ') return
			if(target.matches('th[data-key]')) {
				e.preventDefault()
				this.sortBy(target.getAttribute('data-key'))
			} else if(target.matches('tbody tr[data-id]')) {
				e.preventDefault()
				this.emit('selectortester:detail', { id: Number(target.getAttribute('data-id')) })
			}
		}

		onInput(e) {
			var target = e.target
			if(!(target instanceof Element) || !target.matches('.st-filter')) return
			var self = this
			clearTimeout(this.filterTimer)
			this.filterTimer = setTimeout(function() { self.applyFilter(target.value) }, 120)
		}

		applyFilter(value) {
			clearTimeout(this.filterTimer)
			this.filterText = String(value || '')
			var filter = this.filterText.trim().toLowerCase()
			if(filter === this.filter) return
			this.filter = filter
			this.renderRows()
		}
	}

	if(!customElements.get('selector-tester-results')) {
		customElements.define('selector-tester-results', SelectorTesterResults)
	}

	function request(url, body, signal) {
		return fetch(url, {
			method: 'POST',
			body: body,
			signal: signal,
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
		}).then(function(res) {
			return res.text().then(function(text) {
				var data = null
				try { data = JSON.parse(text) } catch(err) {}
				if(!data || typeof data !== 'object') {
					var snippet = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200)
					throw new Error('HTTP ' + res.status + (snippet ? ': ' + snippet : ''))
				}
				if(!res.ok || data.error || data.ok === false) throw new Error(data.message || 'HTTP ' + res.status)
				return data
			})
		})
	}

	function copyFormData(source) {
		var copy = new FormData()
		source.forEach(function(value, key) { copy.append(key, value) })
		return copy
	}

	function detailHtml(data) {
		var props = (data.props || []).map(function(p) {
			return '<tr><th class="uk-text-nowrap">' + esc(p[0]) + '</th><td>' + p[1] + '</td></tr>'
		}).join('')
		//field html comes from Fieldtype::markupValue(), already encoded server side
		var fields = (data.fields || []).map(function(f) {
			return '<dt>' + esc(f.label) + ' <small class="uk-text-muted">' + esc(f.name) + ' · ' + esc(f.type) + '</small></dt>' +
				'<dd>' + (f.html ? f.html : '<span class="uk-text-muted">' + esc(labels.empty) + '</span>') + '</dd>'
		}).join('')

		return '<div class="uk-modal-header">' +
				'<h2 class="uk-modal-title">' + esc(data.title) + ' <small class="uk-text-muted">#' + esc(data.id) + '</small></h2>' +
			'</div>' +
			'<div class="uk-modal-body st-detail">' +
				'<table class="uk-table uk-table-small uk-table-divider st-props"><tbody>' + props + '</tbody></table>' +
				(fields ? '<h3>' + esc(labels.fields) + '</h3><dl class="uk-description-list uk-description-list-divider st-fields">' + fields + '</dl>' : '') +
			'</div>' +
			'<div class="uk-modal-footer uk-text-right">' +
				'<a class="uk-button uk-button-default" href="' + esc(data.editUrl) + '" target="_blank">' + esc(labels.edit) + '</a> ' +
				'<button class="uk-button uk-button-primary uk-modal-close" type="button">' + esc(labels.close) + '</button>' +
			'</div>'
	}

	function init() {
		var form = document.getElementById('SelectorTesterForm')
		var results = document.querySelector('selector-tester-results')
		if(!form || !results) return

		var submit = form.querySelector('[type=submit]')
		var submitIcon = submit ? submit.querySelector('.fa') : null
		var findCtl = null
		var detailCtl = null
		var lastQuery = null
		var modal = null

		function busy(on) {
			results.setBusy(on)
			if(submitIcon) {
				submitIcon.classList.toggle('fa-search', !on)
				submitIcon.classList.toggle('fa-spinner', on)
				submitIcon.classList.toggle('fa-spin', on)
			}
		}

		function find(query, append) {
			if(findCtl) findCtl.abort()
			var ctl = findCtl = new AbortController()
			var body = copyFormData(query)
			body.set('start', append ? results.nextStart : 0)
			busy(true)
			request(cfg.findUrl, body, ctl.signal).then(function(data) {
				if(ctl !== findCtl) return
				lastQuery = query
				results.setData(data, append)
			}).catch(function(err) {
				if(ctl !== findCtl || err.name === 'AbortError') return
				results.showError(err.message)
			}).then(function() {
				if(ctl !== findCtl) return
				findCtl = null
				busy(false)
			})
		}

		function openDetail(id) {
			if(detailCtl) detailCtl.abort()
			var ctl = detailCtl = new AbortController()
			var body = new FormData()
			var token = form.querySelector('input._post_token')
			if(token) body.append(token.name, token.value)
			body.append('id', id)
			results.setBusy(true)
			request(cfg.pageUrl, body, ctl.signal).then(function(data) {
				if(ctl !== detailCtl) return
				showModal(detailHtml(data))
			}).catch(function(err) {
				if(ctl !== detailCtl || err.name === 'AbortError') return
				results.showError(err.message)
			}).then(function() {
				if(ctl !== detailCtl) return
				detailCtl = null
				results.setBusy(false)
			})
		}

		function showModal(html) {
			if(modal) modal.hide()
			if(window.UIkit && UIkit.modal) {
				modal = UIkit.modal.dialog(html, { stack: false })
				modal.$el.classList.add('uk-modal-container', 'st-modal')
				return
			}
			//no UIkit (another admin theme): a native dialog with the same markup
			var dialog = document.createElement('dialog')
			dialog.className = 'st-modal st-dialog'
			dialog.innerHTML = html
			dialog.addEventListener('click', function(e) {
				if(e.target === dialog || (e.target instanceof Element && e.target.closest('.uk-modal-close'))) dialog.close()
			})
			dialog.addEventListener('close', function() {
				dialog.remove()
				if(modal && modal.el === dialog) modal = null
			})
			document.body.appendChild(dialog)
			dialog.showModal()
			modal = { el: dialog, hide: function() { dialog.close() } }
		}

		form.addEventListener('submit', function(e) {
			e.preventDefault()
			results.showError('')
			find(new FormData(form), false)
		})
		results.addEventListener('selectortester:more', function() {
			if(lastQuery) find(lastQuery, true)
		})
		results.addEventListener('selectortester:detail', function(e) {
			results.showError('')
			openDetail(e.detail.id)
		})
	}

	if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init)
	else init()
})()
