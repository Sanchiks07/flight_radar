<x-layout>
    <h1>Laravel Google Maps</h1>
    <div id="map"></div>

    <script src="https://maps.googleapis.com/maps/api/js?key={{ env('GOOGLE_MAP_KEY') }}&loading=async&callback=initMap" async></script>

    <script>
        let map, activeInfoWindow, markers = [];

        /* ---------- Initialize Map ---------- */
        function initMap() {
            map = new google.maps.Map(document.getElementById("map"), {
                center: {
                    lat: 56.8801729,
                    lng: 24.6057484,
                },
                zoom: 7
            });

            map.addListener("click", function(event) {
                mapClicked(event);
            });
        }
        
        /* ---------- Handle Map Click Event ---------- */
        function mapClicked(event) {
            console.log(map);
            console.log(event.latLng.lat(), event.latLng.lng());
        }

        /* ---------- Handle Marker Click Event ---------- */
        function markerClicked(marker, index) {
            console.log(map);
            console.log(marker.position.lat());
            console.log(marker.position.lng());
        }

        /* ---------- Handle Marker DragEnd Event ---------- */
        function markerDragEnd(event, index) {
            console.log(map);
            console.log(event.latLng.lat());
            console.log(event.latLng.lng());
        }
    </script>
</x-layout>