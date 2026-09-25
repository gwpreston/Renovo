/**
 * The browser half of the WebAuthn ceremonies.
 *
 * Small and framework-free on purpose: the rest of the application works
 * without JavaScript, and this is the one feature that cannot — the credential
 * APIs live in the browser and nowhere else.
 *
 * The only work done here is the encoding the API demands: challenges and ids
 * travel as base64url in JSON and have to be ArrayBuffers going in, and the
 * reverse coming out. Nothing is decided on this side; every response is
 * verified by the server, which is the only place a decision counts.
 */
(function () {
    'use strict';

    /**
     * A message from the catalogue the layout rendered, under this file's own
     * prefix. The key is the fallback, which is what a page that somehow
     * rendered without the catalogue will show.
     */
    function message(key, params) {
        const i18n = window.renovoI18n;

        return i18n ? i18n.t('passkey_' + key, params) : key;
    }

    function base64UrlToBuffer(value) {
        const padded = value.replace(/-/g, '+').replace(/_/g, '/');
        const binary = atob(padded + '='.repeat((4 - (padded.length % 4)) % 4));
        const bytes = new Uint8Array(binary.length);

        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }

        return bytes.buffer;
    }

    function bufferToBase64Url(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';

        for (let i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }

        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function post(url, csrf, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf,
            },
            body: body === undefined ? '{}' : JSON.stringify(body),
        });
    }

    async function readError(response) {
        try {
            const payload = await response.json();

            return payload.error || message('generic_error');
        } catch (error) {
            return message('generic_error');
        }
    }

    function show(element, message) {
        if (!element) {
            window.alert(message);

            return;
        }

        element.textContent = message;
        element.hidden = false;
    }

    function clear(element) {
        if (element) {
            element.hidden = true;
            element.textContent = '';
        }
    }

    function supported() {
        return typeof window.PublicKeyCredential !== 'undefined' && window.isSecureContext;
    }

    /**
     * Sign in, or present a second factor, with an existing credential.
     */
    function bindAuthentication(config) {
        if (!config.button) {
            return;
        }

        if (!supported()) {
            config.button.disabled = true;
            show(config.error, message('unsupported'));

            return;
        }

        config.button.addEventListener('click', async () => {
            clear(config.error);
            config.button.disabled = true;

            try {
                const optionsResponse = await post(config.optionsUrl, config.csrf);
                if (!optionsResponse.ok) {
                    show(config.error, await readError(optionsResponse));

                    return;
                }

                const options = await optionsResponse.json();
                options.challenge = base64UrlToBuffer(options.challenge);
                options.allowCredentials = (options.allowCredentials || []).map((credential) => ({
                    ...credential,
                    id: base64UrlToBuffer(credential.id),
                }));

                const assertion = await navigator.credentials.get({ publicKey: options });

                const verifyResponse = await post(config.verifyUrl, config.csrf, {
                    id: assertion.id,
                    rawId: bufferToBase64Url(assertion.rawId),
                    type: assertion.type,
                    response: {
                        clientDataJSON: bufferToBase64Url(assertion.response.clientDataJSON),
                        authenticatorData: bufferToBase64Url(assertion.response.authenticatorData),
                        signature: bufferToBase64Url(assertion.response.signature),
                        userHandle: assertion.response.userHandle
                            ? bufferToBase64Url(assertion.response.userHandle)
                            : null,
                    },
                    clientExtensionResults: assertion.getClientExtensionResults(),
                });

                if (!verifyResponse.ok) {
                    show(config.error, await readError(verifyResponse));

                    return;
                }

                const result = await verifyResponse.json();
                window.location.assign(result.redirect || '/');
            } catch (error) {
                // A user who dismisses the browser prompt lands here. That is
                // not a failure worth shouting about.
                show(config.error, error.name === 'NotAllowedError'
                    ? message('not_used')
                    : message('use_failed', {reason: error.message}));
            } finally {
                config.button.disabled = false;
            }
        });
    }

    /**
     * Register a new credential against the signed-in account.
     */
    function bindRegistration(config) {
        if (!config.button) {
            return;
        }

        // The control arrives hidden, because without script it is a button
        // that does nothing. From here on it either works or says why not.
        if (config.container) {
            config.container.hidden = false;
        }

        if (!supported()) {
            config.button.disabled = true;
            show(config.error, message('register_unsupported'));

            return;
        }

        config.button.addEventListener('click', async () => {
            clear(config.error);
            config.button.disabled = true;

            try {
                const optionsResponse = await post(config.optionsUrl, config.csrf);
                if (!optionsResponse.ok) {
                    show(config.error, await readError(optionsResponse));

                    return;
                }

                const options = await optionsResponse.json();
                options.challenge = base64UrlToBuffer(options.challenge);
                options.user.id = base64UrlToBuffer(options.user.id);
                options.excludeCredentials = (options.excludeCredentials || []).map((credential) => ({
                    ...credential,
                    id: base64UrlToBuffer(credential.id),
                }));

                const credential = await navigator.credentials.create({ publicKey: options });
                const extensions = credential.getClientExtensionResults();

                const registerResponse = await post(config.registerUrl, config.csrf, {
                    name: config.nameInput ? config.nameInput.value : '',
                    discoverable: !!(extensions.credProps && extensions.credProps.rk),
                    credential: {
                        id: credential.id,
                        rawId: bufferToBase64Url(credential.rawId),
                        type: credential.type,
                        response: {
                            clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                            attestationObject: bufferToBase64Url(credential.response.attestationObject),
                            transports: credential.response.getTransports
                                ? credential.response.getTransports()
                                : [],
                        },
                        clientExtensionResults: extensions,
                    },
                });

                if (!registerResponse.ok) {
                    show(config.error, await readError(registerResponse));

                    return;
                }

                const result = await registerResponse.json();
                window.location.assign(result.redirect || '/profile#two-step');
            } catch (error) {
                show(config.error, error.name === 'NotAllowedError'
                    ? message('register_cancelled')
                    : message('register_failed', {reason: error.message}));
            } finally {
                config.button.disabled = false;
            }
        });
    }

    window.renovoWebAuthn = { bindAuthentication, bindRegistration };
})();
