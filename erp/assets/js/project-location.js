let map;
let marker;
let circle;
let geocoder;
let placesService;
let autocompleteService;

function byId(id) {
    return document.getElementById(id);
}

function hasGoogleMapsLoaded() {
    return typeof google !== "undefined" &&
        google.maps &&
        google.maps.places &&
        typeof google.maps.Map === "function";
}

function initMap() {
    const mapEl = byId("locationMap");

    if (!mapEl) {
        console.warn("Google Map container #locationMap not found.");
        return;
    }

    if (!hasGoogleMapsLoaded()) {
        console.warn("Google Maps API not loaded yet.");
        return;
    }

    const defaultLat = 20.5937;
    const defaultLng = 78.9629;

    const latInput = byId("latitude");
    const lngInput = byId("longitude");
    const radiusInput = byId("location_radius");

    if (!latInput || !lngInput || !radiusInput) {
        console.warn("Required map input fields are missing. Required IDs: latitude, longitude, location_radius.");
        return;
    }

    const savedLat = parseFloat(latInput.value) || defaultLat;
    const savedLng = parseFloat(lngInput.value) || defaultLng;
    const savedRadius = parseInt(radiusInput.value, 10) || 100;

    geocoder = new google.maps.Geocoder();

    map = new google.maps.Map(mapEl, {
        center: {
            lat: savedLat,
            lng: savedLng
        },
        zoom: latInput.value && lngInput.value ? 16 : 5,
        mapTypeControl: true,
        streetViewControl: true,
        fullscreenControl: true,
        mapTypeId: google.maps.MapTypeId.ROADMAP
    });

    placesService = new google.maps.places.PlacesService(map);
    autocompleteService = new google.maps.places.AutocompleteService();

    if (latInput.value && lngInput.value) {
        updateMarker(savedLat, savedLng, savedRadius);
    }

    map.addListener("click", function (e) {
        updateMarker(e.latLng.lat(), e.latLng.lng());
        reverseGeocode(e.latLng.lat(), e.latLng.lng());
    });

    setupSearch();
}

window.initMap = initMap;

function updateMarker(lat, lng, radius) {
    if (!map) return;

    const position = {
        lat: parseFloat(lat),
        lng: parseFloat(lng)
    };

    if (marker) marker.setMap(null);
    if (circle) circle.setMap(null);

    marker = new google.maps.Marker({
        position: position,
        map: map,
        draggable: true,
        animation: google.maps.Animation.DROP,
        title: "Site Location"
    });

    marker.addListener("dragend", function () {
        const pos = marker.getPosition();
        updateFormFields(pos.lat(), pos.lng());
        reverseGeocode(pos.lat(), pos.lng());
        updateCircle(pos.lat(), pos.lng());
    });

    const radiusInput = byId("location_radius");
    const radiusVal = radius || parseInt(radiusInput?.value, 10) || 100;

    circle = new google.maps.Circle({
        map: map,
        center: position,
        radius: radiusVal,
        fillColor: "#10b981",
        fillOpacity: 0.12,
        strokeColor: "#10b981",
        strokeOpacity: 0.65,
        strokeWeight: 2
    });

    updateFormFields(lat, lng);
    map.setCenter(position);
    map.setZoom(16);
}

function updateFormFields(lat, lng) {
    const latInput = byId("latitude");
    const lngInput = byId("longitude");

    if (latInput) latInput.value = Number(lat).toFixed(8);
    if (lngInput) lngInput.value = Number(lng).toFixed(8);
}

function updateCircle(lat, lng) {
    if (circle) {
        circle.setCenter({
            lat: parseFloat(lat),
            lng: parseFloat(lng)
        });
    }
}

function reverseGeocode(lat, lng) {
    if (!geocoder) return;

    geocoder.geocode({
        location: {
            lat: parseFloat(lat),
            lng: parseFloat(lng)
        }
    }, function (results, status) {
        if (status === "OK" && results[0]) {
            const addressInput = byId("location_address");
            const placeInput = byId("place_id");

            if (addressInput) addressInput.value = results[0].formatted_address || "";
            if (placeInput) placeInput.value = results[0].place_id || "";
        }
    });
}

function getCurrentLocation() {
    if (!navigator.geolocation) {
        alert("Geolocation is not supported by your browser.");
        return;
    }

    navigator.geolocation.getCurrentPosition(
        function (position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            updateMarker(lat, lng);
            reverseGeocode(lat, lng);
        },
        function (error) {
            let errorMsg = "Unable to get your location. ";

            if (error.code === error.PERMISSION_DENIED) errorMsg += "Please enable location services.";
            else if (error.code === error.POSITION_UNAVAILABLE) errorMsg += "Location information unavailable.";
            else if (error.code === error.TIMEOUT) errorMsg += "Location request timed out.";

            alert(errorMsg);
        },
        {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0
        }
    );
}

window.getCurrentLocation = getCurrentLocation;

function focusLocationSearch() {
    const input = byId("locationSearch");
    if (input) input.focus();
}

window.focusLocationSearch = focusLocationSearch;

function setupSearch() {
    const searchInput = byId("locationSearch");
    const suggestionsDiv = byId("suggestions");

    if (!searchInput || !suggestionsDiv || !autocompleteService || !placesService) return;

    searchInput.addEventListener("input", function () {
        const query = this.value.trim();

        if (query.length < 3) {
            suggestionsDiv.style.display = "none";
            return;
        }

        autocompleteService.getPlacePredictions({
            input: query,
            componentRestrictions: {
                country: "in"
            }
        }, function (predictions, status) {
            if (status === google.maps.places.PlacesServiceStatus.OK && predictions) {
                suggestionsDiv.innerHTML = "";

                predictions.forEach(function (prediction) {
                    const div = document.createElement("div");
                    div.className = "suggestion-item";
                    div.textContent = prediction.description;

                    div.onclick = function () {
                        selectPlace(prediction.place_id, prediction.description);
                        suggestionsDiv.style.display = "none";
                        searchInput.value = prediction.description;
                    };

                    suggestionsDiv.appendChild(div);
                });

                suggestionsDiv.style.display = "block";
            } else {
                suggestionsDiv.style.display = "none";
            }
        });
    });

    document.addEventListener("click", function (e) {
        if (!searchInput.contains(e.target) && !suggestionsDiv.contains(e.target)) {
            suggestionsDiv.style.display = "none";
        }
    });
}

function selectPlace(placeId, description) {
    if (!placesService) return;

    placesService.getDetails({
        placeId: placeId,
        fields: ["geometry", "formatted_address", "place_id", "name"]
    }, function (place, status) {
        if (status === google.maps.places.PlacesServiceStatus.OK && place.geometry) {
            const lat = place.geometry.location.lat();
            const lng = place.geometry.location.lng();

            updateMarker(lat, lng);

            const addressInput = byId("location_address");
            const placeInput = byId("place_id");

            if (addressInput) addressInput.value = place.formatted_address || description;
            if (placeInput) placeInput.value = placeId;
        }
    });
}

document.addEventListener("DOMContentLoaded", function () {
    const radius = byId("location_radius");

    if (radius) {
        radius.addEventListener("input", function () {
            const lat = parseFloat(byId("latitude")?.value);
            const lng = parseFloat(byId("longitude")?.value);
            const radiusVal = parseInt(this.value, 10) || 100;

            if (!Number.isNaN(lat) && !Number.isNaN(lng) && circle) {
                circle.setRadius(radiusVal);
            }
        });
    }

    const form = byId("projectForm");

    if (form) {
        form.addEventListener("submit", function (e) {
            const start = form.querySelector("[name='start_date']");
            const end = form.querySelector("[name='expected_completion_date']");
            const scopeChecks = form.querySelectorAll("input[name='scope_of_work[]']");
            const hasScope = Array.from(scopeChecks).some(function (cb) {
                return cb.checked;
            });

            if (!hasScope) {
                e.preventDefault();
                alert("Please select at least one scope of work.");
                return;
            }

            if (start && end && start.value && end.value && new Date(end.value) < new Date(start.value)) {
                e.preventDefault();
                alert("Expected completion date cannot be earlier than start date.");
            }
        });
    }
});
