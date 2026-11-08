/*!
 * gismatul/laravel-support-chat widget
 * Plain JS, no dependencies. Messages are rendered with textContent only, never innerHTML.
 *
 * Usage:
 *   1. Laravel Blade:      <x-support-chat::widget />
 *   2. Any other frontend: <script src="https://api.example.com/api/support-chat/assets/support-chat.js"
 *                                  data-config-url="https://api.example.com/api/support-chat/config" defer></script>
 *   3. SPA, manually:      window.SupportChat.init({ configUrl: '...', locale: 'en', authToken: () => token })
 *
 * Transport: short polling of GET .../messages?after_id=N. Requests never send cookies
 * (credentials: 'omit'); a conversation is authorised by the token kept in localStorage.
 */
;(function (window, document) {
    'use strict'

    if (window.SupportChat && window.SupportChat.version) {
        return
    }

    var STORAGE_KEY = 'support_chat_session'
    var CLOSED_POLL_INTERVAL = 30000
    var MAX_BACKOFF = 30000
    var instance = null
    var currentScript = document.currentScript

    // ---------- tiny DOM helper ----------

    function h(tag, attrs, children) {
        var node = document.createElement(tag)

        Object.keys(attrs || {}).forEach(function (key) {
            var value = attrs[key]

            if (value === null || value === undefined || value === false) {
                return
            }

            if (key === 'text') {
                node.textContent = value
            } else if (key === 'className') {
                node.className = value
            } else {
                node.setAttribute(key, value === true ? '' : value)
            }
        })
        ;(children || []).forEach(function (child) {
            if (child) {
                node.appendChild(child)
            }
        })

        return node
    }

    function svg(path, size) {
        var ns = 'http://www.w3.org/2000/svg'
        var el = document.createElementNS(ns, 'svg')
        var p = document.createElementNS(ns, 'path')

        el.setAttribute('width', size)
        el.setAttribute('height', size)
        el.setAttribute('viewBox', '0 0 24 24')
        el.setAttribute('aria-hidden', 'true')
        p.setAttribute('fill', 'currentColor')
        p.setAttribute('d', path)
        el.appendChild(p)

        return el
    }

    var ICON_CHAT = 'M12 3C6.5 3 2 6.6 2 11c0 2.2 1.1 4.2 2.9 5.6-.2 1.4-.9 2.7-2 3.6 2.2 0 4.1-.8 5.5-2 1.1.3 2.3.5 3.6.5 5.5 0 10-3.6 10-8S17.5 3 12 3zm-5 9.3a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6zm5 0a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6zm5 0a1.3 1.3 0 1 1 0-2.6 1.3 1.3 0 0 1 0 2.6z'
    var ICON_CLOSE = 'M18.3 5.7a1 1 0 0 0-1.4 0L12 10.6 7.1 5.7a1 1 0 0 0-1.4 1.4l4.9 4.9-4.9 4.9a1 1 0 1 0 1.4 1.4l4.9-4.9 4.9 4.9a1 1 0 0 0 1.4-1.4L13.4 12l4.9-4.9a1 1 0 0 0 0-1.4z'
    var ICON_SEND = 'M3.4 20.4 21.9 12 3.4 3.6 3.3 10l13 2-13 2z'

    // ---------- widget ----------

    function Widget(config, options) {
        var t = config.i18n || {}
        var fields = config.fields || {}
        var state = {
            session: loadSession(),
            isOpen: false,
            lastId: 0,
            seen: {},
            status: null,
            pollTimer: null,
            polling: false,
            failures: 0,
            sending: false,
            pendingSend: null, // { text, clientId } reused on retry to avoid duplicates
            historyLoaded: false,
            endNotice: null,
            lastName: null,
            destroyed: false,
        }

        // ----- markup -----

        function contactInput(name, type, mode, autocomplete, maxlength) {
            if (mode === 'hidden') {
                return null
            }

            var required = mode === 'required'

            return h('input', {
                type: type,
                name: name,
                maxlength: maxlength,
                autocomplete: autocomplete,
                required: required,
                placeholder: required ? t[name] + ' *' : t[name + '_placeholder'],
                'aria-label': t[name],
            })
        }

        var el = {}

        el.badge = h('span', { className: 'sc-chat__badge', hidden: true })
        el.launcher = h('button', { type: 'button', className: 'sc-chat__launcher', 'aria-controls': 'sc-chat-panel', 'aria-expanded': 'false', title: t.open, 'aria-label': t.open }, [
            (function () { var i = svg(ICON_CHAT, 26); i.setAttribute('class', 'sc-chat__icon-open'); return i })(),
            (function () { var i = svg(ICON_CLOSE, 22); i.setAttribute('class', 'sc-chat__icon-close'); return i })(),
            el.badge,
        ])
        el.close = h('button', { type: 'button', className: 'sc-chat__close', title: t.close, 'aria-label': t.close, text: '×' })
        el.messages = h('div', { className: 'sc-chat__messages', role: 'log', 'aria-live': 'polite' }, [
            h('div', { className: 'sc-chat__msg sc-chat__msg--operator' }, [h('div', { className: 'sc-chat__bubble', text: t.greeting })]),
        ])
        el.intro = h('form', { className: 'sc-chat__intro', novalidate: true, hidden: true }, [
            h('p', { className: 'sc-chat__intro-text', text: t.intro }),
            h('input', { type: 'text', name: 'name', maxlength: 120, required: true, autocomplete: 'name', placeholder: t.name_placeholder, 'aria-label': t.name }),
            contactInput('email', 'email', fields.email, 'email', 120),
            contactInput('phone', 'tel', fields.phone, 'tel', 30),
            h('input', { type: 'text', name: 'website', className: 'sc-chat__hp', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }),
            h('textarea', { name: 'message', rows: 3, required: true, maxlength: config.maxLength, placeholder: t.message_placeholder, 'aria-label': t.message }),
            h('button', { type: 'submit', className: 'sc-chat__btn', text: t.start }),
        ])
        el.body = h('div', { className: 'sc-chat__body' }, [el.messages, el.intro])
        el.notice = h('div', { className: 'sc-chat__notice', role: 'alert', hidden: true })
        el.composer = h('form', { className: 'sc-chat__composer', novalidate: true, hidden: true }, [
            h('textarea', { name: 'message', rows: 1, maxlength: config.maxLength, placeholder: t.message_placeholder, 'aria-label': t.message }),
            h('button', { type: 'submit', className: 'sc-chat__send', title: t.send, 'aria-label': t.send }, [svg(ICON_SEND, 20)]),
        ])
        el.endedText = h('p', { text: t.closed_notice })
        el.restart = h('button', { type: 'button', className: 'sc-chat__btn sc-chat__restart', text: t.new_chat })
        el.ended = h('div', { className: 'sc-chat__ended', hidden: true }, [el.endedText, el.restart])
        el.panel = h('section', { className: 'sc-chat__panel', id: 'sc-chat-panel', role: 'dialog', 'aria-label': t.title, hidden: true }, [
            h('header', { className: 'sc-chat__header' }, [
                h('div', {}, [h('div', { className: 'sc-chat__title', text: t.title }), h('div', { className: 'sc-chat__subtitle', text: t.subtitle })]),
                el.close,
            ]),
            el.body,
            el.notice,
            el.composer,
            el.ended,
        ])

        var root = h('div', { className: 'sc-chat', id: 'sc-support-chat' }, [el.launcher, el.panel])

        if (options.primaryColor) {
            root.style.setProperty('--sc-chat-primary', options.primaryColor)
        }

        ;(options.container || document.body).appendChild(root)

        // ----- storage -----

        function loadSession() {
            try {
                var data = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || 'null')

                return data && data.uuid && data.token ? data : null
            } catch (e) {
                return null
            }
        }

        function saveSession(session) {
            state.session = session

            try {
                if (session) {
                    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(session))
                } else {
                    window.localStorage.removeItem(STORAGE_KEY)
                }
            } catch (e) {
                // Storage unavailable (private mode): the chat still works until the page is reloaded.
            }
        }

        // ----- helpers -----

        function uuid() {
            if (window.crypto && window.crypto.randomUUID) {
                return window.crypto.randomUUID()
            }

            if (window.crypto && window.crypto.getRandomValues) {
                var b = window.crypto.getRandomValues(new Uint8Array(16))
                b[6] = (b[6] & 0x0f) | 0x40
                b[8] = (b[8] & 0x3f) | 0x80
                var hex = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2) }).join('')

                return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20)
            }

            return null
        }

        function messagesUrl() {
            return config.messagesUrl.replace('__UUID__', encodeURIComponent(state.session.uuid))
        }

        function request(method, url, body, withAuth) {
            var headers = { Accept: 'application/json' }

            if (body) {
                headers['Content-Type'] = 'application/json'
            }

            if (state.session) {
                headers['X-Chat-Token'] = state.session.token
            }

            if (withAuth) {
                Object.assign(headers, authHeaders(options))
            }

            return fetch(url, {
                method: method,
                headers: headers,
                body: body ? JSON.stringify(body) : undefined,
                credentials: 'omit',
                cache: 'no-store',
            }).then(function (response) {
                return response
                    .json()
                    .catch(function () {
                        return {}
                    })
                    .then(function (json) {
                        return { status: response.status, ok: response.ok, json: json }
                    })
            })
        }

        function firstError(json) {
            if (json && json.errors) {
                var keys = Object.keys(json.errors)

                if (keys.length && json.errors[keys[0]].length) {
                    return json.errors[keys[0]][0]
                }
            }

            return t.error_send
        }

        function showNotice(text) {
            el.notice.textContent = text || ''
            el.notice.hidden = !text
        }

        function formatTime(iso) {
            var date = new Date(iso)

            if (isNaN(date.getTime())) {
                return ''
            }

            var time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })

            return date.toDateString() === new Date().toDateString() ? time : date.toLocaleDateString() + ' ' + time
        }

        function isNearBottom() {
            return el.body.scrollHeight - el.body.scrollTop - el.body.clientHeight < 80
        }

        function scrollToBottom() {
            el.body.scrollTop = el.body.scrollHeight
        }

        // ----- rendering -----

        function renderMessage(message) {
            if (!message || state.seen[message.id]) {
                return false
            }

            state.seen[message.id] = true
            state.lastId = Math.max(state.lastId, message.id)

            var sender = ['customer', 'operator', 'system'].indexOf(message.sender) !== -1 ? message.sender : 'system'
            var bubble = h('div', { className: 'sc-chat__bubble' })
            var wrapper = h('div', { className: 'sc-chat__msg sc-chat__msg--' + sender }, [bubble])

            if (message.event === 'closed' || message.event === 'expired') {
                state.endNotice = message.event === 'expired' ? t.expired_notice : t.closed_notice
                bubble.textContent = state.endNotice
            } else {
                bubble.textContent = message.message
            }

            if (sender !== 'system') {
                wrapper.appendChild(h('div', { className: 'sc-chat__meta', text: (sender === 'customer' ? t.you : t.operator) + ' · ' + formatTime(message.created_at) }))
            }

            el.messages.appendChild(wrapper)

            return true
        }

        function renderMessages(messages, forceScroll) {
            var stick = forceScroll || isNearBottom()
            var hasOperatorMessage = false

            ;(messages || []).forEach(function (message) {
                if (renderMessage(message) && message.sender !== 'customer') {
                    hasOperatorMessage = true
                }
            })

            if (stick) {
                scrollToBottom()
            }

            // History loaded on page refresh is not "unread"; only replies arriving afterwards are.
            if (hasOperatorMessage && !state.isOpen && state.historyLoaded) {
                el.badge.hidden = false
            }
        }

        function resetMessages() {
            while (el.messages.children.length > 1) {
                el.messages.removeChild(el.messages.lastChild)
            }

            state.lastId = 0
            state.seen = {}
            state.status = null
            state.historyLoaded = false
            state.endNotice = null
        }

        function setMode(mode) {
            el.intro.hidden = mode !== 'intro'
            el.composer.hidden = mode !== 'chat'
            el.ended.hidden = mode !== 'ended'
        }

        function applyStatus(status) {
            state.status = status

            if (status === 'closed') {
                el.endedText.textContent = state.endNotice || t.closed_notice
                setMode('ended')
                stopPolling()
            } else {
                setMode('chat')
            }
        }

        // ----- polling -----

        function schedulePoll(delay) {
            clearTimeout(state.pollTimer)

            if (!state.session || state.status === 'closed' || state.destroyed) {
                return
            }

            state.pollTimer = setTimeout(poll, delay)
        }

        function nextDelay() {
            var base = state.isOpen ? config.pollInterval : CLOSED_POLL_INTERVAL

            return state.failures ? Math.min(base * Math.pow(2, state.failures), MAX_BACKOFF) : base
        }

        function stopPolling() {
            clearTimeout(state.pollTimer)
            state.pollTimer = null
        }

        function poll() {
            if (!state.session || state.polling || state.destroyed) {
                return
            }

            // Paused while the tab is hidden; visibilitychange resumes it.
            if (document.hidden) {
                return
            }

            state.polling = true

            request('GET', messagesUrl() + '?after_id=' + state.lastId)
                .then(function (res) {
                    if (res.status === 404) {
                        expireSession()

                        return
                    }

                    if (!res.ok) {
                        throw new Error('HTTP ' + res.status)
                    }

                    state.failures = 0

                    if (el.notice.textContent === t.error_network) {
                        showNotice('')
                    }

                    renderMessages(res.json.data.messages, false)
                    state.historyLoaded = true
                    applyStatus(res.json.data.conversation.status)
                })
                .catch(function () {
                    state.failures++

                    if (state.isOpen) {
                        showNotice(t.error_network)
                    }
                })
                .then(function () {
                    state.polling = false
                    schedulePoll(nextDelay())
                })
        }

        function expireSession() {
            saveSession(null)
            stopPolling()
            resetMessages()
            showIntro()
            showNotice(t.error_expired)
        }

        // ----- actions -----

        function showIntro() {
            setMode('intro')

            var prefill = config.prefill || {}
            var form = el.intro

            ;['name', 'email', 'phone'].forEach(function (field) {
                if (form.elements[field] && !form.elements[field].value && prefill[field]) {
                    form.elements[field].value = prefill[field]
                }
            })

            if (!form.elements.name.value && state.lastName) {
                form.elements.name.value = state.lastName
            }
        }

        function startConversation(event) {
            event.preventDefault()

            var form = el.intro
            var button = form.querySelector('button[type="submit"]')
            var text = form.elements.message.value.trim()

            if (button.disabled) {
                return
            }

            var missing = Array.prototype.find.call(form.querySelectorAll('[required]'), function (input) {
                return !input.value.trim()
            })

            if (missing) {
                missing.focus()

                return
            }

            if (!state.pendingSend || state.pendingSend.text !== text) {
                state.pendingSend = { text: text, clientId: uuid() }
            }

            button.disabled = true
            button.textContent = t.sending
            showNotice('')

            request('POST', config.startUrl, {
                name: form.elements.name.value,
                email: form.elements.email ? form.elements.email.value : '',
                phone: form.elements.phone ? form.elements.phone.value : '',
                website: form.elements.website.value,
                message: text,
                client_message_id: state.pendingSend.clientId,
            }, true)
                .then(function (res) {
                    if (res.status === 201) {
                        var data = res.json.data

                        state.pendingSend = null
                        saveSession({ uuid: data.conversation.uuid, token: data.token, name: data.conversation.customer_name })
                        form.elements.message.value = ''
                        renderMessages(data.messages, true)
                        state.historyLoaded = true
                        applyStatus(data.conversation.status)
                        schedulePoll(config.pollInterval)
                        el.composer.elements.message.focus()

                        return
                    }

                    showNotice(res.status === 429 ? t.error_rate : res.status === 422 ? firstError(res.json) : t.error_send)
                })
                .catch(function () {
                    showNotice(t.error_send)
                })
                .then(function () {
                    button.disabled = false
                    button.textContent = t.start
                })
        }

        function sendMessage(event) {
            event.preventDefault()

            var textarea = el.composer.elements.message
            var button = el.composer.querySelector('button[type="submit"]')
            var text = textarea.value.trim()

            if (!text || state.sending || !state.session) {
                return
            }

            // Same text after a failure => same client id, so the server de-duplicates a retried request.
            if (!state.pendingSend || state.pendingSend.text !== text) {
                state.pendingSend = { text: text, clientId: uuid() }
            }

            state.sending = true
            button.disabled = true
            textarea.readOnly = true

            request('POST', messagesUrl(), { message: text, client_message_id: state.pendingSend.clientId })
                .then(function (res) {
                    if (res.status === 201 || res.status === 200) {
                        state.pendingSend = null
                        textarea.value = ''
                        autoResize()
                        showNotice('')
                        renderMessages([res.json.data], true)

                        return
                    }

                    if (res.status === 409) {
                        applyStatus('closed')
                        poll()

                        return
                    }

                    if (res.status === 404) {
                        expireSession()

                        return
                    }

                    showNotice(res.status === 429 ? t.error_rate : res.status === 422 ? firstError(res.json) : t.error_send)
                })
                .catch(function () {
                    showNotice(t.error_send)
                })
                .then(function () {
                    state.sending = false
                    button.disabled = false
                    textarea.readOnly = false
                    textarea.focus()
                })
        }

        function restart() {
            state.lastName = state.session && state.session.name
            saveSession(null)
            stopPolling()
            resetMessages()
            showNotice('')
            showIntro()
            el.intro.elements.message.focus()
        }

        function autoResize() {
            var textarea = el.composer.elements.message

            textarea.style.height = 'auto'
            textarea.style.height = Math.min(textarea.scrollHeight + 2, 120) + 'px'
        }

        function open() {
            state.isOpen = true
            root.classList.add('is-open')
            el.panel.hidden = false
            el.badge.hidden = true
            el.launcher.setAttribute('aria-expanded', 'true')
            el.launcher.title = t.close

            if (state.session) {
                setMode(state.status === 'closed' ? 'ended' : 'chat')
                state.failures = 0
                poll()
                scrollToBottom()
                el.composer.elements.message.focus()
            } else {
                showIntro()
                el.intro.elements[el.intro.elements.name.value ? 'message' : 'name'].focus()
            }
        }

        function close() {
            state.isOpen = false
            root.classList.remove('is-open')
            el.panel.hidden = true
            el.launcher.setAttribute('aria-expanded', 'false')
            el.launcher.title = t.open
            showNotice('')

            // Keep a slow background poll so an operator reply can show the unread badge.
            schedulePoll(CLOSED_POLL_INTERVAL)
        }

        // ----- wiring -----

        function onLauncher() {
            state.isOpen ? close() : open()
        }

        function onComposerKey(event) {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                sendMessage(event)
            }
        }

        function onDocumentKey(event) {
            if (event.key === 'Escape' && state.isOpen) {
                close()
            }
        }

        function onWake() {
            if (!document.hidden && state.session && state.status !== 'closed') {
                state.failures = 0
                poll()
            }
        }

        el.launcher.addEventListener('click', onLauncher)
        el.close.addEventListener('click', close)
        el.intro.addEventListener('submit', startConversation)
        el.composer.addEventListener('submit', sendMessage)
        el.restart.addEventListener('click', restart)
        el.composer.elements.message.addEventListener('input', autoResize)
        el.composer.elements.message.addEventListener('keydown', onComposerKey)
        document.addEventListener('keydown', onDocumentKey)
        document.addEventListener('visibilitychange', onWake)
        window.addEventListener('online', onWake)

        // Restore an existing conversation after a page refresh.
        if (state.session) {
            schedulePoll(0)
        }

        return {
            open: open,
            close: close,
            destroy: function () {
                state.destroyed = true
                stopPolling()
                document.removeEventListener('keydown', onDocumentKey)
                document.removeEventListener('visibilitychange', onWake)
                window.removeEventListener('online', onWake)
                root.parentNode && root.parentNode.removeChild(root)
            },
        }
    }

    // ---------- bootstrap ----------

    function authHeaders(options) {
        var token = typeof options.authToken === 'function' ? options.authToken() : options.authToken

        return token ? { Authorization: 'Bearer ' + token } : {}
    }

    function ensureStylesheet(href) {
        if (!href || document.querySelector('link[data-support-chat-css]')) {
            return
        }

        var link = document.createElement('link')
        link.rel = 'stylesheet'
        link.href = href
        link.setAttribute('data-support-chat-css', '')
        document.head.appendChild(link)
    }

    function mount(config, options) {
        if (instance) {
            instance.destroy()
        }

        instance = Widget(config, options)

        return instance
    }

    /**
     * @param {Object} options
     * @param {Object} [options.config]     Full config object (as rendered by the Blade component).
     * @param {string} [options.configUrl]  URL of GET .../config; used when no config object is given.
     * @param {string} [options.locale]     Language of the widget texts (e.g. "en", "az", "ru").
     * @param {string|Function} [options.authToken]  Bearer token of a logged-in customer (prefill + link).
     * @param {string} [options.stylesheet] URL of support-chat.css to inject (when not already on the page).
     * @param {string} [options.primaryColor]
     * @param {Element} [options.container]
     * @returns {Promise<{open: Function, close: Function, destroy: Function}>}
     */
    function init(options) {
        options = options || {}

        if (!window.fetch || !window.Promise) {
            return Promise.reject(new Error('SupportChat requires fetch and Promise support.'))
        }

        ensureStylesheet(options.stylesheet)

        if (options.config) {
            return Promise.resolve(mount(options.config, options))
        }

        if (!options.configUrl) {
            return Promise.reject(new Error('SupportChat.init() needs a config or a configUrl.'))
        }

        var url = options.configUrl + (options.locale ? (options.configUrl.indexOf('?') === -1 ? '?' : '&') + 'locale=' + encodeURIComponent(options.locale) : '')

        return fetch(url, {
            headers: Object.assign({ Accept: 'application/json' }, authHeaders(options)),
            credentials: 'omit',
            cache: 'no-store',
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('SupportChat config request failed: HTTP ' + response.status)
                }

                return response.json()
            })
            .then(function (json) {
                return mount(json.data, options)
            })
    }

    function autoInit() {
        // 1. Inline config rendered by the Blade component.
        var inline = document.getElementById('support-chat-config')

        if (inline) {
            try {
                init({ config: JSON.parse(inline.textContent) })
            } catch (e) {
                window.console && console.error('[SupportChat] Invalid inline config', e)
            }

            return
        }

        // 2. <script src=".../support-chat.js" data-config-url="..." data-locale="az" data-stylesheet="...">
        if (currentScript && currentScript.getAttribute('data-config-url')) {
            init({
                configUrl: currentScript.getAttribute('data-config-url'),
                locale: currentScript.getAttribute('data-locale') || document.documentElement.lang || undefined,
                stylesheet: currentScript.getAttribute('data-stylesheet') || undefined,
                primaryColor: currentScript.getAttribute('data-primary-color') || undefined,
            }).catch(function (e) {
                window.console && console.error(e)
            })
        }
    }

    window.SupportChat = {
        version: '1.0.0',
        init: init,
        open: function () { instance && instance.open() },
        close: function () { instance && instance.close() },
        destroy: function () { instance && instance.destroy(); instance = null },
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoInit)
    } else {
        autoInit()
    }
})(window, document)
