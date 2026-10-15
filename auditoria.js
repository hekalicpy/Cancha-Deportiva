let ultimoIdAuditoria = null;
let cargaAuditoria = 0;

function consultarAuditoria(event){
    event.preventDefault();
    cargarAuditoria();
}

function limpiarAuditoria(){
    document.getElementById('filtrosAuditoria').reset();
    cargarAuditoria();
}

function cargarMasAuditoria(){
    if(ultimoIdAuditoria !== null) cargarAuditoria(true);
}

async function cargarAuditoria(anteriores = false){
    const solicitud = ++cargaAuditoria;
    const tabla = document.getElementById('tablaAuditoria');
    const estado = document.getElementById('estadoAuditoria');
    const mas = document.getElementById('masAuditoria');
    if(!anteriores){
        tabla.replaceChildren();
        ultimoIdAuditoria = null;
    }
    mas.style.display = 'none';
    estado.textContent = 'Cargando historial…';
    const params = new URLSearchParams();
    for(const [campo, nombre] of [
        ['auditoriaUsuario','usuario'], ['auditoriaAccion','accion'],
        ['auditoriaRecursoId','recurso_id'], ['auditoriaDesde','desde'], ['auditoriaHasta','hasta']
    ]){
        const valor = document.getElementById(campo).value.trim();
        if(valor) params.set(nombre, valor);
    }
    if(anteriores && ultimoIdAuditoria !== null) params.set('before_id', ultimoIdAuditoria);
    try {
        const response = await fetch('api.php/auditoria?' + params.toString(), {cache:'no-store'});
        const rows = await response.json();
        if(!response.ok) throw new Error(rows.error || 'No se pudo consultar el historial.');
        if(solicitud !== cargaAuditoria) return;
        const acciones = {
            LOGIN_OK:'Inicio correcto', LOGIN_FAIL:'Inicio fallido',
            RESERVA_CREAR:'Reserva creada', RESERVA_EDITAR:'Reserva editada',
            RESERVA_CANCELAR:'Reserva cancelada', RESERVA_CONFIRMAR:'Reserva confirmada',
            PAGO_REGISTRAR:'Pago registrado', PAGO_CONFIRMAR_MANUAL:'Pago confirmado'
        };
        for(const row of rows){
            const fila = document.createElement('tr');
            let detalle = row.detalle || '';
            if(detalle.startsWith('{')){
                try {detalle = JSON.stringify(JSON.parse(detalle));} catch(e) {}
            }
            for(const valor of [row.creado, row.usuario, acciones[row.accion] || row.accion,
                row.recurso, row.recurso_id || '', detalle]){
                const celda = document.createElement('td');
                celda.textContent = valor == null ? '' : String(valor);
                fila.appendChild(celda);
            }
            tabla.appendChild(fila);
        }
        ultimoIdAuditoria = rows.length ? rows[rows.length - 1].id : null;
        mas.style.display = rows.length === 100 ? '' : 'none';
        estado.textContent = tabla.rows.length ? `${tabla.rows.length} eventos cargados.` : 'No hay eventos para estos filtros.';
    } catch(error){
        if(solicitud !== cargaAuditoria) return;
        estado.textContent = error.message;
    }
}
