<x-layout>
    <div id="globeViz"></div>
    <div id="time"></div>

    <div id="infoPopup">
        <button type="button" id="closeInfoBtn" aria-label="Close">X</button>
        <div id="infoContent"></div>
    </div>

    <script type="module">
        import { TextureLoader, ShaderMaterial, Vector2 } from 'https://esm.sh/three';
        import * as solar from 'https://esm.sh/solar-calculator';

        const VELOCITY = 1;
        let lastPlaneHash = '';

        // Custom shader: Blends night and day images to simulate day/night cycle
        const dayNightShader = {
            vertexShader: `
                varying vec3 vNormal;
                varying vec2 vUv;
                void main() {
                    vNormal = normalize(normalMatrix * normal);
                    vUv = uv;
                    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
                }
            `,
            fragmentShader: `
                #define PI 3.141592653589793
                uniform sampler2D dayTexture;
                uniform sampler2D nightTexture;
                uniform vec2 sunPosition;
                uniform vec2 globeRotation;
                varying vec3 vNormal;
                varying vec2 vUv;

                float toRad(in float a) {
                    return a * PI / 180.0;
                }

                vec3 Polar2Cartesian(in vec2 c) { // [lng, lat]
                    float theta = toRad(90.0 - c.x);
                    float phi = toRad(90.0 - c.y);
                    return vec3(
                        sin(phi) * cos(theta),
                        cos(phi),
                        sin(phi) * sin(theta)
                    );
                }

                void main() {
                    float invLon = toRad(globeRotation.x);
                    float invLat = -toRad(globeRotation.y);

                    mat3 rotX = mat3(
                        1, 0, 0,
                        0, cos(invLat), -sin(invLat),
                        0, sin(invLat), cos(invLat)
                    );

                    mat3 rotY = mat3(
                        cos(invLon), 0, sin(invLon),
                        0, 1, 0,
                        -sin(invLon), 0, cos(invLon)
                    );

                    vec3 rotatedSunDirection = rotX * rotY * Polar2Cartesian(sunPosition);
                    float intensity = dot(normalize(vNormal), normalize(rotatedSunDirection));

                    vec4 dayColor = texture2D(dayTexture, vUv);
                    vec4 nightColor = texture2D(nightTexture, vUv);

                    float blendFactor = smoothstep(-0.1, 0.1, intensity);
                    gl_FragColor = mix(nightColor, dayColor, blendFactor);
                }
            `
        };

        const sunPosAt = dt => {
            const day = new Date(+dt).setUTCHours(0, 0, 0, 0);
            const t = solar.century(dt);
            const longitude = (day - dt) / 864e5 * 360 - 180;
            return [longitude - solar.equationOfTime(t) / 4, solar.declination(t)];
        };

        let dt = Date.now(); // ms timestamp used for sun calculation
        const timeEl = document.getElementById('time');
        // Time scaling: 1 = real-time, <1 = slower, >1 = faster
        const time_scale = 1;
        let lastRealTime = Date.now();

        // Format date as DD/MM/YYYY HH:mm:ss
        function formatDateTime(ms) {
            const d = new Date(ms);
            const dd = String(d.getDate()).padStart(2, '0');
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const yyyy = d.getFullYear();
            const hh = String(d.getHours()).padStart(2, '0');
            const min = String(d.getMinutes()).padStart(2, '0');
            const sec = String(d.getSeconds()).padStart(2, '0');
            return `${dd}/${mm}/${yyyy} ${hh}:${min}:${sec}`;
        }

        const world = new Globe(document.getElementById('globeViz'));

        world.pointOfView({ lat: 45, lng: 15, altitude: 1 }, 2000); // load in view - pēdējie cipari ir speed

        // Plane Icon Loop
        // const plane_api = 'https://opensky-network.org/api/states/all'; // live api
        const plane_api = '/plane.json'; // demo local file

        let activePlaneId = null;
        const default_src = "/plane.png";
        const active_src = "/active-plane.png";

        // setup plane layer
        world
            .htmlElementsData([])
            .htmlElement(d => {
                const el = document.createElement('div');
                el.style.position = 'relative';
                el.style.pointerEvents = 'auto';

                // plane icon
                const img = document.createElement('img');
                img.classList.add('plane-icon');
                img.src = (d.id === activePlaneId) ? active_src : default_src;
                img.style.width = '20px';
                img.style.height = '20px';
                img.style.display = 'block';
                img.style.transform = `rotate(${d.heading}deg)`;
                img.style.cursor = 'pointer';
                img.style.willChange = 'transform';

                // plane info on click
                img.onclick = () => {
                    // reset previously active plane icon
                    activePlaneId = d.id;

                    // reset all plane icons visually
                    document.querySelectorAll('.plane-icon').forEach(i => {
                        i.src = default_src;
                    });

                    img.src = active_src;

                    const info = document.getElementById('infoPopup');
                    const infoContent = document.getElementById('infoContent');
                    const closeBtn = document.getElementById('closeInfoBtn');

                    info.style.display = 'block';
                    closeBtn.style.display = 'block';

                    const onGroundText =
                        d.on_ground === true || d.on_ground === false
                            ? (d.on_ground ? 'Yes' : 'No')
                            : 'Unknown';

                    infoContent.innerHTML = '';
                    const addRow = (label, value) => {
                        const row = document.createElement('div');
                        row.className = 'info-row';

                        const lab = document.createElement('div');
                        lab.className = 'info-label';
                        lab.textContent = label;

                        const val = document.createElement('div');
                        val.className = 'info-value';
                        val.textContent = value;

                        row.appendChild(lab);
                        row.appendChild(val);
                        infoContent.appendChild(row);
                    };

                    addRow('Plane', d.callsign || 'Unknown');
                    addRow('Lng', d.lng);
                    addRow('Lat', d.lat);
                    addRow('On Ground', onGroundText);
                    addRow('Velocity', (d.velocity != null) ? Number((d.velocity * 3.6).toFixed(2)) + ' km/h' : 'Unknown');
                    addRow('Baro Alt', (d.baro_altitude != null) ? d.baro_altitude + ' m' : 'Unknown');
                    addRow('Geo Alt', d.geo_altitude || 'Unknown');
                    addRow('Last Contact', (d.last_contact != null) ? formatDateTime(d.last_contact * 1000) : 'Unknown');

                    // info popup close button function
                    closeBtn.onclick = e => {
                        e.stopPropagation();

                        // reset icon on close
                        activePlaneId = null;
                        document.querySelectorAll('.plane-icon').forEach(i => {
                            i.src = default_src;
                        });

                        info.style.display = 'none';
                        closeBtn.style.display = 'none';
                        infoContent.textContent = '';
                    };
                };

                el.appendChild(img);

                // callsign label for plane
                const label = document.createElement('div');
                label.textContent = d.callsign || 'Unknown';
                label.className = 'plane-label';
                el.appendChild(label);

                // show/hide label on hover and bring hovered plane to front
                el.addEventListener('mouseenter', () => {
                    el.classList.add('plane-hovered');
                    el.style.zIndex = '1000000';
                });

                el.addEventListener('mouseleave', () => {
                    el.classList.remove('plane-hovered');
                    el.style.zIndex = '';
                });

                return el;
            });

        // fetch planes
        async function loadPlanes() {
            try {
                const response = await fetch(plane_api);
                if (!response.ok) throw new Error('API down or rate-limited');

                const data = await response.json();
                if (!data.states) return;

                // maps each plane to the fields
                const planes = data.states
                    .filter(p => p[5] != null && p[6] != null)
                    .map(p => ({
                        id: p[0],
                        callsign: p[1]?.trim(),
                        last_contact: p[4],
                        lng: p[5],
                        lat: p[6],
                        baro_altitude: p[7],
                        on_ground: p[8],
                        velocity: p[9],
                        heading: p[10] - 45,
                        geo_altitude: p[13]
                    }));

                const hash = JSON.stringify(
                    planes.map(p => [p.id, p.lng, p.lat, p.heading])
                );

                if (hash !== lastPlaneHash) {
                    lastPlaneHash = hash;
                    world.htmlElementsData(planes);
                }

            } catch (e) {
                console.log('Could not fetch planes, API might be down:', e.message);
            }
        }

        // loop
        loadPlanes();
        setInterval(loadPlanes, 15000);

        Promise.all([
            new TextureLoader().loadAsync('/world-day.jpg'), //day image
            new TextureLoader().loadAsync('/world-night.jpg') //night image
        ]).then(([dayTexture, nightTexture]) => {
            const material = new ShaderMaterial({
                uniforms: {
                    dayTexture: { value: dayTexture },
                    nightTexture: { value: nightTexture },
                    sunPosition: { value: new Vector2() },
                    globeRotation: { value: new Vector2() }
                },
                vertexShader: dayNightShader.vertexShader,
                fragmentShader: dayNightShader.fragmentShader
            });

            world
                .globeMaterial(material)
                .backgroundImageUrl('//cdn.jsdelivr.net/npm/three-globe/example/img/night-sky.png')
                // Update globe rotation on shader
                .onZoom(({ lng, lat }) =>
                    material.uniforms.globeRotation.value.set(lng, lat)
                );

            requestAnimationFrame(() =>
                (function animate() {
                    // animate time of day using real elapsed time scaled by time_scale
                    const now = Date.now();
                    const elapsed = now - lastRealTime; // ms
                    lastRealTime = now;
                    dt += elapsed * time_scale; // advance scaled time

                    // updates visible clock every 1s (reduce DOM updates)
                    if (!window._lastClockUpdate || now - window._lastClockUpdate >= 1000) {
                        timeEl.textContent = formatDateTime(dt);
                        window._lastClockUpdate = now;
                    }

                    // updates sun position less frequently (every 250ms)
                    if (!window._lastSunUpdate || now - window._lastSunUpdate >= 250) {
                        material.uniforms.sunPosition.value.set(...sunPosAt(dt));
                        window._lastSunUpdate = now;
                    }

                    requestAnimationFrame(animate);
                })()
            );
        });
    </script>
</x-layout>
