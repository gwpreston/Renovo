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

            return payload.error || 'That did not work. Try again.';
        } catch (error) {
            return 'That did not work. Try again.';
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
            show(config.error, 'This browser cannot use passkeys on this connection.');

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
                    ? 'No passkey was used.'
                    : 'That passkey could not be used: ' + error.message);
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

        if (!supported()) {
            config.button.disabled = true;
            show(config.error, 'This browser cannot register passkeys on this connection.');

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
                window.location.assign(result.redirect || '/settings/security');
            } catch (error) {
                show(config.error, error.name === 'NotAllowedError'
                    ? 'Registration was cancelled.'
                    : 'That key could not be registered: ' + error.message);
            } finally {
                config.button.disabled = false;
            }
        });
    }

    window.renovoWebAuthn = { bindAuthentication, bindRegistration };
})();
