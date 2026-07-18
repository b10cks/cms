<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        html, body { margin: 0; padding: 0; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; }
    </style>
</head>
<body>
<div id="app"></div>
<script>
    (function () {
        'use strict'

        var match = /(?:^|[#&])b10cks-token=([^&]+)/.exec(location.hash)
        var token = match ? decodeURIComponent(match[1]) : null
        var handlers = null
        var mounted = false

        function send(type, payload) {
            window.parent.postMessage({
                source: 'b10cks-plugin',
                version: 1,
                token: token,
                type: type,
                payload: payload || {},
            }, '*')
        }

        var api = {
            data: null,
            setValue: function (value) { send('VALUE_CHANGE', { value: value }) },
            setHeight: function (height) { send('HEIGHT_CHANGE', { height: height }) },
            toggleModal: function (open) { send('MODAL_TOGGLE', { open: open }) },
            selectAsset: function () { return Promise.reject(new Error('unsupported')) },
        }

        function observeHeight() {
            if (!('ResizeObserver' in window)) return
            var observer = new ResizeObserver(function () {
                send('HEIGHT_CHANGE', { height: document.documentElement.scrollHeight })
            })
            observer.observe(document.documentElement)
            observer.observe(document.getElementById('app'))
        }

        window.addEventListener('message', function (event) {
            if (event.source !== window.parent) return

            var data = event.data
            if (!data || data.source !== 'b10cks-plugin' || data.token !== token || data.version !== 1) return

            if (data.type === 'INIT') {
                if (mounted) return
                mounted = true
                api.data = data.payload

                var plugin = window.b10cksFieldPlugin
                if (!plugin || typeof plugin.mount !== 'function') return

                handlers = plugin.mount(document.getElementById('app'), api) || {}
                observeHeight()
            } else if (data.type === 'VALUE_UPDATE') {
                if (handlers && handlers.onValueUpdate) handlers.onValueUpdate(data.payload.value)
            } else if (data.type === 'READ_ONLY_UPDATE') {
                if (handlers && handlers.onReadOnlyUpdate) handlers.onReadOnlyUpdate(data.payload.readOnly)
            } else if (data.type === 'THEME') {
                if (handlers && handlers.onTheme) handlers.onTheme(data.payload.theme)
            }
        })

        {{-- @json escapes </script> and HTML-relevant characters (HEX_TAG et al.),
             so the bundle can be embedded and executed without Blade-echoing raw JS. --}}
        {{-- Library bundles (React et al.) often reference process.env.NODE_ENV,
             which doesn't exist in a browser sandbox — shim it before injection. --}}
        window.process = window.process || { env: { NODE_ENV: 'production' } }

        {{-- The semicolon is load-bearing: Blade swallows the newline after
             @json, putting the next statement on the same line. --}}
        var bundle = @json($plugin->code);
        var script = document.createElement('script')
        script.textContent = bundle
        document.head.appendChild(script)

        {{-- Only signal readiness when the bundle actually registered itself; a broken
             bundle then hits the host's timeout, which shows the load-failed UI. --}}
        var plugin = window.b10cksFieldPlugin
        if (plugin && typeof plugin.mount === 'function') {
            send('PLUGIN_READY')
        }
    })()
</script>
</body>
</html>
