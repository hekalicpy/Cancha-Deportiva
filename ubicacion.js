'use strict';
(() => {
    let loading;
    function link(label, href) {
        const url = new URL(href);
        if (url.origin !== 'https://www.google.com' || !url.pathname.startsWith('/maps/')) {
            throw new Error('Enlace de ubicación inválido.');
        }
        const a = document.createElement('a');
        a.textContent = label;
        a.href = url.href;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.className = 'btn';
        return a;
    }
    async function load() {
        if (loading) return loading;
        const panels = document.querySelectorAll('[data-ubicacion]');
        panels.forEach(panel => { panel.textContent = 'Cargando ubicación…'; });
        loading = (async () => {
            try {
                const response = await fetch('ubicacion.php', {cache: 'no-store', headers: {Accept: 'application/json'}});
                if (!response.ok) throw new Error('Ubicación no disponible.');
                const venue = await response.json();
                panels.forEach(panel => {
                    const title = document.createElement('h3');
                    title.textContent = 'Ubicación de ' + venue.nombre;
                    const address = document.createElement('p');
                    address.textContent = venue.local + ' · ' + venue.direccion;
                    const actions = document.createElement('div');
                    actions.className = 'botones ubicacion-acciones';
                    actions.append(link('Ver ubicación', venue.mapa), link('Cómo llegar', venue.como_llegar));
                    panel.replaceChildren(title, address, actions);
                });
            } catch (error) {
                panels.forEach(panel => {
                    const message = document.createElement('p');
                    message.textContent = 'No se pudo cargar la ubicación del local.';
                    const retry = document.createElement('button');
                    retry.type = 'button';
                    retry.className = 'btn';
                    retry.textContent = 'Reintentar';
                    retry.addEventListener('click', load);
                    panel.replaceChildren(message, retry);
                });
            } finally { loading = null; }
        })();
        return loading;
    }
    load();
})();
