{{-- Carte du lieu (Leaflet + OpenStreetMap) : le repère se déplace à la souris et suit les champs latitude / longitude. --}}
<div
    wire:ignore
    x-data="{
        latitude: $wire.$entangle('data.latitude'),
        longitude: $wire.$entangle('data.longitude'),
        carte: null,
        repere: null,
        async init() {
            if (! window.L) {
                const css = document.createElement('link');
                css.rel = 'stylesheet';
                css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                document.head.appendChild(css);
                await new Promise((ok) => {
                    const js = document.createElement('script');
                    js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                    js.onload = ok;
                    document.head.appendChild(js);
                });
            }
            const lat = parseFloat(this.latitude) || 46.6;
            const lon = parseFloat(this.longitude) || 2.4;
            this.carte = L.map(this.$refs.carte).setView([lat, lon], this.latitude ? 17 : 5);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© contributeurs OpenStreetMap',
            }).addTo(this.carte);
            this.repere = L.marker([lat, lon], { draggable: true }).addTo(this.carte);
            this.repere.on('dragend', () => {
                const p = this.repere.getLatLng();
                this.latitude = p.lat.toFixed(7);
                this.longitude = p.lng.toFixed(7);
            });
            this.carte.on('click', (e) => {
                this.repere.setLatLng(e.latlng);
                this.latitude = e.latlng.lat.toFixed(7);
                this.longitude = e.latlng.lng.toFixed(7);
            });
            this.$watch('latitude', () => this.deplacer());
            this.$watch('longitude', () => this.deplacer());
            setTimeout(() => this.carte.invalidateSize(), 200);
        },
        deplacer() {
            const lat = parseFloat(this.latitude), lon = parseFloat(this.longitude);
            if (! isNaN(lat) && ! isNaN(lon)) { this.repere.setLatLng([lat, lon]); }
        },
    }"
>
    <div x-ref="carte" style="height: 320px; border-radius: 0.75rem; z-index: 0;"></div>
</div>
