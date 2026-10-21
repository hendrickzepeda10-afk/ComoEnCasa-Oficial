(() => {
    const parametro = '__tab';
    const parametroPadre = '__tab_parent';
    const claveAlmacenamiento = 'comoEnCasaPestana';
    const claveLease = claveAlmacenamiento + ':lease:';
    const tokenValido = /^[a-f0-9]{64}$/;

    const crearToken = () => {
        const bytes = new Uint8Array(32);
        crypto.getRandomValues(bytes);
        return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    };
    const crearIdInstancia = () => {
        const bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    };
    const leerAlmacenamiento = key => {
        try {
            return JSON.parse(localStorage.getItem(key) || 'null');
        } catch {
            return null;
        }
    };
    const escribirAlmacenamiento = (key, value) => {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch {
            return false;
        }
        return true;
    };
    const parametroValido = value => typeof value === 'string' && tokenValido.test(value);
    const urlActual = new URL(window.location.href);
    const tokenUrl = urlActual.searchParams.get(parametro);
    const tokenAlmacenado = sessionStorage.getItem(claveAlmacenamiento);
    let token = parametroValido(tokenUrl)
        ? tokenUrl
        : (parametroValido(tokenAlmacenado) ? tokenAlmacenado : crearToken());
    const idInstancia = crearIdInstancia();
    const claveLeaseActual = claveLease + token;
    const leaseAnterior = leerAlmacenamiento(claveLeaseActual);
    const edadLease = leaseAnterior ? Date.now() - leaseAnterior.actualizado : Infinity;
    const leaseVigente = leaseAnterior
        && leaseAnterior.token === token
        && leaseAnterior.instancia !== idInstancia
        && edadLease >= 0
        && edadLease < 120000;

    if (leaseVigente) {
        const tokenPadre = token;
        token = crearToken();
        sessionStorage.setItem(claveAlmacenamiento, token);
        urlActual.searchParams.set(parametro, token);
        urlActual.searchParams.set(parametroPadre, tokenPadre);
        window.location.replace(urlActual.toString());
        return;
    }

    sessionStorage.setItem(claveAlmacenamiento, token);
    const tokenEnUrl = urlActual.searchParams.get(parametro);
    if (tokenEnUrl !== token) {
        urlActual.searchParams.set(parametro, token);
        if (!parametroValido(tokenUrl) && !parametroValido(tokenAlmacenado)) {
            const firmaLegado = window.comoEnCasaFirmaSesionAnterior;
            if (typeof firmaLegado === 'string' && /^[a-f0-9]{64}$/.test(firmaLegado)) {
                urlActual.searchParams.set(parametroPadre, 'legacy.' + firmaLegado);
            } else {
                urlActual.searchParams.delete(parametroPadre);
            }
        }
        window.location.replace(urlActual.toString());
        return;
    }

    if (urlActual.searchParams.has(parametroPadre)) {
        urlActual.searchParams.delete(parametroPadre);
        window.history.replaceState(window.history.state, '', urlActual.toString());
    }

    const guardarLease = () => escribirAlmacenamiento(claveLeaseActual, {
        token: token,
        instancia: idInstancia,
        actualizado: Date.now(),
    });
    let latido;
    const iniciarLease = () => {
        guardarLease();
        window.clearInterval(latido);
        latido = window.setInterval(guardarLease, 10000);
    };
    const detenerLease = () => {
        window.clearInterval(latido);
        const lease = leerAlmacenamiento(claveLeaseActual);
        if (lease && lease.instancia === idInstancia) {
            try {
                localStorage.removeItem(claveLeaseActual);
            } catch {
                return;
            }
        }
    };
    iniciarLease();
    window.addEventListener('pageshow', iniciarLease);
    window.addEventListener('pagehide', detenerLease);

    const agregarToken = value => {
        try {
            const url = new URL(value, window.location.href);
            if (url.origin !== window.location.origin) return value;
            url.searchParams.set(parametro, token);
            url.searchParams.delete(parametroPadre);
            return url.href;
        } catch {
            return value;
        }
    };
    const actualizarElemento = elemento => {
        if (elemento instanceof HTMLAnchorElement && elemento.href) {
            const href = agregarToken(elemento.href);
            if (elemento.href !== href) elemento.href = href;
        } else if (elemento instanceof HTMLFormElement) {
            const action = agregarToken(elemento.action || window.location.href);
            if (elemento.action !== action) elemento.action = action;
            if (elemento.method.toLowerCase() === 'post') {
                let campo = elemento.querySelector('input[name="' + parametro + '"]');
                if (!campo) {
                    campo = document.createElement('input');
                    campo.type = 'hidden';
                    campo.name = parametro;
                    elemento.appendChild(campo);
                }
                if (campo.value !== token) campo.value = token;
            }
        } else if (elemento instanceof HTMLButtonElement && elemento.formAction) {
            const formAction = agregarToken(elemento.formAction);
            if (elemento.formAction !== formAction) elemento.formAction = formAction;
        } else if (elemento instanceof HTMLInputElement && elemento.formAction) {
            const formAction = agregarToken(elemento.formAction);
            if (elemento.formAction !== formAction) elemento.formAction = formAction;
        }
    };
    const actualizarSubarbol = nodo => {
        if (!(nodo instanceof Element)) return;
        actualizarElemento(nodo);
        nodo.querySelectorAll('a[href], form, button[formaction], input[formaction]').forEach(actualizarElemento);
    };

    actualizarSubarbol(document.documentElement);
    new MutationObserver(mutations => {
        mutations.forEach(mutation => {
            mutation.addedNodes.forEach(actualizarSubarbol);
            if (mutation.type === 'attributes') actualizarElemento(mutation.target);
        });
    }).observe(document.documentElement, {
        subtree: true,
        childList: true,
        attributes: true,
        attributeFilter: ['href', 'action', 'formaction'],
    });

    document.addEventListener('submit', event => actualizarElemento(event.target), true);

    const fetchOriginal = window.fetch.bind(window);
    window.fetch = (input, init) => {
        if (input instanceof Request) {
            const request = new Request(agregarToken(input.url), input);
            return fetchOriginal(request, init);
        }
        return fetchOriginal(agregarToken(input), init);
    };

    const abrirOriginal = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (metodo, url, ...opciones) {
        return abrirOriginal.call(this, metodo, agregarToken(url), ...opciones);
    };

    if (navigator.sendBeacon) {
        const enviarBeaconOriginal = navigator.sendBeacon.bind(navigator);
        navigator.sendBeacon = (url, datos) => enviarBeaconOriginal(agregarToken(url), datos);
    }

    const abrirVentanaOriginal = window.open.bind(window);
    window.open = (url, ...opciones) => abrirVentanaOriginal(
        url == null ? url : agregarToken(url),
        ...opciones
    );
})();
