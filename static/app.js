let root = document.body;

function Indexed(array) {
  this.all = array;
  this.indexed = {};
  for (let i = 0; i < array.length; i++) {
    if (array[i].id in this.indexed) throw new Error("duplicated id");
    this.indexed[array[i].id] = array[i];
  }
}
Indexed.prototype.add = function(entry) {
  if (entry.id in this.indexed) {
    for (let i = 0; i < this.all.length; i++) {
      if (this.all[i].id == entry.id) {
        this.all[i] = entry;
      }
    }
  } else {
    this.all.push(entry);
  }
  this.indexed[entry.id] = entry;
};

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
  add: (name, kerberos) => {
    return m.request({
      method: 'POST',
      url: '/api/person',
      withCredentials: true,
      data: {
        name: name,
        kerberos: kerberos
      }
    })
    .then((result) => {
      People.data.add(result);
    })
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
    .then((result) => {
      Superlatives.data.add(result);
    });
  },
  vote: (superlative, people) => {
    // note: takes objects, not ids

    return m.request({
      method: 'POST',
      url: '/api/vote',
      withCredentials: true,
      data: {
        superlative: superlative.id,
        people: people.map(person => person.id)
      }
    })
    .then((result) => {
      Superlatives.data.add(result);
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
