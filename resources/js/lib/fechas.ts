// El servidor envía las fechas en UTC (ISO 8601); se muestran en la zona
// horaria del navegador.
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
