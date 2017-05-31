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
  /*
   * [{
   *       "name": str,
   *       "kerberos": str,
   * }]
   */
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
        name: name,
      }
    })
    .then((result) => {
      People.data.add(result);
    })
  }
};

Superlatives = {
  /*
   * [{
   *       "id": int,
   *       "text": str,
   *       "slots": int,
   *       "people"?: [int]
   * }]
   */
  data: new Indexed([]),
  load: () => {
    return m.request({
      method: 'GET',
      url: '/api/superlatives',
      withCredentials: true
    })
    .then((result) => {
      for (let i = 0; i < result.length; i++) {
        if (_.some(result[i].people, _.isNull)) {
          result[i].status = 'new';
        } else {
          result[i].status = 'committed';
        }
      }
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
  vote: (sup, i, person) => {
    // note: takes objects, not ids
    if (i < 0 || i >= sup.people.length) {
      throw new Error("not enough slots");
    }

    sup.people[i] = person.id;
    sup.status = 'loading';

    if (person.kerberos == 'dstrawse') {
      danning = true;
      setTimeout(() => {
        danning = false;
        m.redraw();
      }, 1000);
    }

    if (!_.some(sup.people, _.isNull)) {
      // run the vote
      return m.request({
        method: 'POST',
        url: '/api/vote',
        withCredentials: true,
        data: {
          superlative: sup.id,
          people: sup.people
        }
      })
      .then((result) => {
        Superlatives.data.add(result);
        result.status = 'committed';
      });
    }
  }
};

let loading = true;
let newperson = false;
let newsuperlative = false;
let danning = false;

// view
let App = {
  oninit: () => Promise.all([People.load(), Superlatives.load()]).then(() => loading = false),
  view: () => {
    let cards = Superlatives.data.all.map((sup) => m(SupCard, { sup: sup }));
    cards.push(m('.tile.notification',
      m('button.button.is-light', { onclick: () => newsuperlative = true }, 'new superlative...')));

    let columns = _.chunk(cards, Math.ceil(cards.length / 3))
      .map(portion => m('.column.is-one-third', portion));

    return [
      m('section.section',
        m('.container.has-text-centered', [
          m('h1.title', 'πτz superlatives'),
          loading ? m('.button.is-loading.is-link', ' ') : null
        ])
      ),
      m('section.section', m('.columns', columns)),
      newperson ? m(NewPerson) : null,
      newsuperlative ? m(NewSuperlative) : null,
      danning ? m('.dan') : null
    ];
  }
};

let colors = ['is-primary', 'is-success', 'is-info', 'is-warning'];
let SupCard = {
  view: (vnode) => {
    let sup = vnode.attrs.sup;

    let marker;
    if (sup.status == 'committed') {
      marker = m('.is-pulled-right', '✓');
    } else if (sup.status == 'loading') {
      marker = m('.loader.is-pulled-right');
    }

    return m('.tile.notification', { class: colors[sup.id % colors.length], key: sup.id }, 
      m('.container', [
        m('p.subtitle', [sup.text, marker]),
        _.range(sup.slots).map(i => 
          m(PersonAutocomplete, { sup: sup, i: i })
        )
      ])
    );
  }
};

let PersonAutocomplete = {
  oninit: (vnode) => {
    vnode.state.focus = false;

    // if text is null, we have a selected person
    // if text is not null, we have a text editing field
    vnode.state.text = vnode.attrs.sup.people[vnode.attrs.i] === null? '' : null;
  },
  view: (vnode) => {
    let state = vnode.state;

    if (vnode.state.text !== null) {
      let options = People.data.all.filter((person) => {
        return fuzzysearch(state.text, person.name) ||
               fuzzysearch(state.text, person.kerberos);
      }).slice(0, 3);

      return m('.field', [
        m('input.input', {
          value: state.text,
          spellcheck: false,
          onfocus: () => state.focus = true,
          onblur: () => state.focus = false,
          oninput: m.withAttr("value", value => {
            state.text = value.toLowerCase();
          }),
          oncreate: (vnode) => {
            if (state.focus) {
              vnode.dom.focus();
            }
          },
          autocomplete: "off",
          autocorrect: "off",
          autocapitalize: "off",
        }),
        state.focus && state.text.length > 0 ? m('ul.options-list', [
          _.range(options.length).map(i => {
            let person = options[i];
            return m('li', {
              onmousedown: () => {
                state.text = null;
                state.focus = false;
                return Superlatives.vote(vnode.attrs.sup, vnode.attrs.i, person);
              }
            }, m(PersonTag, { person: person }));
          }),
          m('li.has-text-centered.new', { onmousedown: () => {
            newperson = true;
          }, }, 'add person...')
        ]) : null
      ]);
    } else {
      let person = People.data.indexed[vnode.attrs.sup.people[vnode.attrs.i]];

      if (!person) throw new Error();

      let onclick = () => {
        state.text = person.kerberos;
        state.focus = true;
      };

      return m('.field', m('.input', {
        onclick: onclick,
      }, m(PersonTag, { person: person })));
    }
  }
};

let PersonTag = {
  view: (vnode) => {
    return m('span.tag', vnode.attrs.person.kerberos + ' (' + vnode.attrs.person.name + ')');
  }
};

let AddModal = {
  view: (vnode) => {
    return m('.modal.is-active', [
      m('.modal-background'),
      m('.modal-card', [
        m('header.modal-card-head', 
          m('p.modal-card-title', vnode.attrs.title),
        ),
        m('section.modal-card-body', vnode.children),
        m('footer.modal-card-foot', [
          m('a.button.is-success', {
            disabled: !vnode.attrs.valid,
            onclick: vnode.attrs.onfinish
          }, 'add'),
          m('a.button', {onclick: vnode.attrs.onclose}, 'cancel')
        ])
      ]),
      m('button.modal-close', {onclick: vnode.attrs.onclose})
    ]);
  }
}

let NewPerson = {
  oninit: (vnode) => {
    vnode.state.name = '';
    vnode.state.kerberos = '';
  },
  view: (vnode) => {
    return m(AddModal, 
      {
        title: 'add person',
        onclose: () => newperson = false,
        onfinish: () => {
          newperson = false;
          return People.add(vnode.state.kerberos, vnode.state.name);
        },
        valid: vnode.state.name != '' && vnode.state.kerberos != ''
      },
      [
        m('.field', [
          m('label.label', 'kerberos'),
          m('input.input', {
            value: vnode.state.kerberos,
            spellcheck: false,
            oninput: m.withAttr("value", (value) => vnode.state.kerberos = value.toLowerCase()),
            autocomplete: "off",
            autocorrect: "off",
            autocapitalize: "off",
          }),
        ]),
        m('.field', [
          m('label.label', 'name'),
          m('input.input', {
            value: vnode.state.name,
            spellcheck: false,
            oninput: m.withAttr("value", (value) => vnode.state.name = value.toLowerCase()),
            autocomplete: "off",
            autocorrect: "off",
            autocapitalize: "off",
          }),
        ])
      ]
    );
  }
};

let NewSuperlative = {
  oninit: (vnode) => {
    vnode.state.text = '';
    vnode.state.slots = 1;
  },
  view: (vnode) => {
    return m(AddModal, 
      {
        title: 'add superlative',
        onclose: () => newsuperlative = false,
        onfinish: () => {
          newsuperlative = false;
          return Superlatives.add(vnode.state.text, vnode.state.slots);
        },
        valid: vnode.state.text != ''
      },
      [
        m('.field', [
          m('label.label', 'superlative'),
          m('input.input', {
            value: vnode.state.text,
            spellcheck: false,
            oninput: m.withAttr("value", (value) => vnode.state.text = value.toLowerCase()),
            autocomplete: "off",
            autocorrect: "off",
            autocapitalize: "off",
          }),
        ]),
        m('.field', [
          m('label.label', 'slots'),
          m('p.control', m('span.select', m('select', {oninput: m.withAttr('value', (value) => vnode.state.slots = Number(value))}, [
            m('option', 1),
            m('option', 2),
            m('option', 3),
            m('option', 4)
          ])))
        ])
      ]
    );
  }
};

// https://github.com/bevacqua/fuzzysearch
//
// The MIT License (MIT)
// 
// Copyright © 2015 Nicolas Bevacqua
// 
// Permission is hereby granted, free of charge, to any person obtaining a copy of
// this software and associated documentation files (the "Software"), to deal in
// the Software without restriction, including without limitation the rights to
// use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
// the Software, and to permit persons to whom the Software is furnished to do so,
// subject to the following conditions:
// 
// The above copyright notice and this permission notice shall be included in all
// copies or substantial portions of the Software.
// 
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
// FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
// COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
// IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
// CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
function fuzzysearch (needle, haystack) {
  var hlen = haystack.length;
  var nlen = needle.length;
  if (nlen > hlen) {
    return false;
  }
  if (nlen === hlen) {
    return needle === haystack;
  }
  outer: for (var i = 0, j = 0; i < nlen; i++) {
    var nch = needle.charCodeAt(i);
    while (j < hlen) {
      if (haystack.charCodeAt(j++) === nch) {
        continue outer;
      }
    }
    return false;
  }
  return true;
}

m.mount(root, App);
