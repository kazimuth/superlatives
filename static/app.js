let root = document.body;

function Indexed(array) {
  this.all = array;
  this.indexed = {};
  for (let i = 0; i < array.length; i++) {
    this.indexed[array[i].id] = array[i];
  }
}

// model
let People = {
  data: new Indexed([]),
  load: () => {
    return m.request({
      method: 'GET',
      url: '/api/people',
      withCredentials: true
    })
    .then((result) => {
      People.data = new Indexed(result);
    });
  },
  add: (kerberos, name) => {
    return m.request({
      method: 'POST',
      url: '/api/person',
      withCredentials: true,
      data: {
        kerberos: kerberos,
        name: name
      }
    })
    .then(People.load)
    .catch((err) => {
      lasterror = err;
    });
  }
};

Superlatives = {
  data: new Indexed([]),
  load: () => {
    return m.request({
      method: 'GET',
      url: '/api/superlatives',
      withCredentials: true
    })
    .then((result) => {
      Superlatives.data = new Indexed(result);
    });
  },
  add: (text, slots) => {
    return m.request({
      method: 'POST',
      url: '/api/superlative',
      withCredentials: true,
      data: {
        text: text,
        slots: slots
      }
    })
    .then(People.load)
    .catch((err) => {
      lasterror = err;
    });
  }
};

let loading = true;
let lasterror = null;

// view
let App = {
  oninit: () => Promise.all([People.load(), Superlatives.load()]).then(() => loading = false),
  view: () => {
    return [
      m('section.section', [
        m('.container', [
          m('h1.title', 'πτz superlatives'),
          loading ? m('button.is-loading.inline.is-link') : null
        ])
      ]),
      m('section.section', Superlatives.data.all.map((sup) => m(SupCard, {sup: sup}))
      )
    ];
  }
};

let colors = ['is-primary', 'is-success', 'is-info', 'is-warning'];
let SupCard = {
  view: function(vnode) {
    let sup = vnode.attrs.sup, votes = vnode.attrs.votes;

    let color = colors[sup.id % colors.length];

    return m('.tile.notification.is-4', {class: color}, [
      m('p.subtitle', sup.text),
      m('p.subtitle', sup.slots),
    ]);
  }
};

let PersonAutocomplete = {

};

m.mount(root, App);
