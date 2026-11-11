import axios from 'axios';

// One shared axios instance: import it (`import axios from 'axios'`) wherever a
// plain XHR is needed. It sends Laravel's XSRF cookie back automatically.
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
