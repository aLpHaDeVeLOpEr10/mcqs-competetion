import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Echo (WebSockets) is loaded only on the live competition page: see resources/js/play.js.
