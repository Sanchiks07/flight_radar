<x-layout>
    <div id="globeViz"></div>
    <div id="infoPopup">
        <button type="button" id="closeInfoBtn" aria-label="Close">X</button>
        <div id="infoContent"></div>
    </div>


    <script type="module">
        import { TextureLoader, ShaderMaterial, Vector2 } from 'https://esm.sh/three';
        import * as solar from 'https://esm.sh/solar-calculator';

        const VELOCITY = 1;

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
                return vec3( // x,y,z
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

        let dt = +new Date();

        const world = new Globe(document.getElementById('globeViz'));

        world.pointOfView({ lat: 40, lng: 10, altitude: 2 }, 1000); // load in view

        // Plane Icon Loop
        // const plane_api = 'https://opensky-network.org/api/states/all';
        const plane_api = '/plane.json';
        const plane_icon = '/plane.png';

        // setup plane layer
        world
        .htmlElementsData([])
        .htmlElement(d => {
            const el = document.createElement('div');
            el.style.position = 'relative';
            el.style.pointerEvents = 'none';
            
            // plane icon
            const img = document.createElement('img');
            img.src = plane_icon;
            // plane info on click
            img.onclick = () => {
                const info = document.getElementById('infoPopup');
                const infoContent = document.getElementById('infoContent');
                const closeBtn = document.getElementById('closeInfoBtn');

                info.style.display = 'block';
                closeBtn.style.display = 'block';
                const onGroundText = (d.on_ground === true || d.on_ground === false)
                    ? (d.on_ground ? 'Yes' : 'No')
                    : 'Unknown';
                const lastContactText = (d.last_contact != null)
                    ? new Date(d.last_contact * 1000).toLocaleString()
                    : 'Unknown';

                infoContent.textContent = 'Plane: ' + (d.callsign || 'Unknown') + '\nLng: ' + (d.lng || 'Unknown') + '\nLat: ' + (d.lat || 'Unknown') + '\nOn Ground: ' + onGroundText + '\nVelocity: ' + ((d.velocity != null) ? (d.velocity + ' m/s') : 'Unknown') + '\nBaro Alt: ' + ((d.baro_altitude != null) ? (d.baro_altitude + ' m') : 'Unknown') + '\nGeo Alt: ' + (d.geo_altitude || 'Unknown') + '\nLast Contact: ' + lastContactText;
                
                closeBtn.onclick = (a) => {
                    a.stopPropagation();
                    info.style.display = 'none';
                    closeBtn.style.display = 'none';
                    infoContent.textContent = '';
                };
            };
            img.style.width = '20px';
            img.style.height = '20px';
            img.style.display = 'block';
            img.style.transform = `rotate(${d.heading}deg)`;
            img.style.cursor = 'pointer';
            el.appendChild(img);
            
            // label for callsign
            const label = document.createElement('div');
            label.textContent = d.callsign || 'Unknown';
            label.style.position = 'absolute';
            label.style.zIndex = 'auto';
            label.style.left = '25px';
            label.style.top = '0px';
            label.style.whiteSpace = 'nowrap';
            label.style.backgroundColor = 'rgba(0, 0, 0, 0.8)';
            label.style.color = 'white';
            label.style.padding = '2px 5px';
            label.style.borderRadius = '3px';
            label.style.fontSize = '12px';
            label.style.display = 'none';
            label.style.pointerEvents = 'none';
            label.style.fontWeight = 'bold';
            el.appendChild(label);
            
            // show/hide label on hover
            el.style.pointerEvents = 'auto';
            el.addEventListener('mouseenter', () => { label.style.display = 'block'; });
            el.addEventListener('mouseleave', () => { label.style.display = 'none'; });
            
            return el;
        });

        // fetch planes
        async function loadPlanes() {
            try {
                const responce = await fetch(plane_api);
                if (!responce.ok) throw new Error('API down or rate-limited');

                const data = await responce.json();
                if (!data.states) return;

                // maps each plane to the fields
                const planes = data.states
                .filter(p => p[5] != null && p[6] != null)
                .map(p => {
                    return {
                        callsign: p[1]?.trim(),
                        last_contact: p[4],
                        lng: p[5],
                        lat: p[6],
                        baro_altitude: p[7],
                        on_ground: p[8],
                        velocity: p[9],
                        heading: (p[10] - 45),
                        geo_altitude: p[13]
                    };
                });

                world.htmlElementsData(planes);

            } catch (e) {
                console.log('Could not fetch planes, API might be down:', e.message);
            }
        }

        // loop
        loadPlanes();

        Promise.all([
            new TextureLoader().loadAsync('//cdn.jsdelivr.net/npm/three-globe/example/img/earth-day.jpg'),
            new TextureLoader().loadAsync('//cdn.jsdelivr.net/npm/three-globe/example/img/earth-night.jpg')
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

        world.globeMaterial(material)
            .backgroundImageUrl('//cdn.jsdelivr.net/npm/three-globe/example/img/night-sky.png')
            // Update globe rotation on shader
            .onZoom(({ lng, lat }) => material.uniforms.globeRotation.value.set(lng, lat));

        requestAnimationFrame(() =>
            (function animate() {
            // animate time of day
            dt += VELOCITY * 60 * 24; // the shadow movement (how fast it moves)
            material.uniforms.sunPosition.value.set(...sunPosAt(dt));
            requestAnimationFrame(animate);
            })()
        );
        });
    </script>
</x-layout>