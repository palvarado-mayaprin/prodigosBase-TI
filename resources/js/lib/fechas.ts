// El servidor envía las fechas en UTC (ISO 8601); se muestran en la zona
// horaria configurada (prop compartida `zonaHoraria`) o, sin ella, en la del
// navegador.
export function fechaHora(iso: string | null, zonaHoraria?: string): string {
    if (!iso) return '—';
    const fecha = new Date(iso);
    if (Number.isNaN(fecha.getTime())) return '—';
    return new Intl.DateTimeFormat('es', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone: zonaHoraria,
    }).format(fecha);
}

// Día local ya calculado por el servidor («AAAA-MM-DD» → «DD/MM/AAAA»).
export function fechaDia(valor: string): string {
    const [a, m, d] = valor.split('-');
    return d && m && a ? `${d}/${m}/${a}` : valor;
}

const numero = new Intl.NumberFormat('es');
const dos = (valor: number) => String(valor).padStart(2, '0');

// Duración legible con segundos exactos: «2 h 05 min 09 s».
export function duracion(segundos: number): string {
    const h = Math.floor(segundos / 3600);
    const m = Math.floor((segundos % 3600) / 60);
    const s = segundos % 60;
    if (h > 0) return `${numero.format(h)} h ${dos(m)} min ${dos(s)} s`;
    if (m > 0) return `${m} min ${dos(s)} s`;
    return `${s} s`;
}
