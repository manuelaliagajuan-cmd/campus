const config = {
            theme: {
                extend: {
                    colors: {
                        /* PALETA DE COLOR CORPORATIVA COMPLETA (Sección 8.1 y 8.2) */
                        campoamor: '#212A5E',    /* 100% */
                        campoamor80: '#4D557E',  /* 80% */
                        campoamor60: '#7A7F9E',  /* 60% */
                        campoamor40: '#A6AABF',  /* 40% */
                        campoamor20: '#D3D4DF',  /* 20% */
                        campoamor10: '#E9EAEF',  /* 10% */
                    },
                    fontFamily: {
                        /* Uso oficial de Arial como tipografía de sustitución (Sección 9.4) */
                        sans: ['Arial', 'Helvetica', 'sans-serif'],
                    },
                    /* Jerarquia tipografica corporativa (Sec 9.2 y 9.3) */
                    fontSize: {
                        'impacto': ['48px', '1.15'],
                        't1': ['39px', '1.15'],
                        't2': ['31px', '1.2'],
                        't3': ['25px', '1.3'],
                        'entradilla': ['20px', '1.5'],
                        'texto': ['16px', '1.5'],
                        'nota': ['13px', '1.5'],
                    },
                    letterSpacing: {
                        'titulares': '0.02em', /* +2% exigido por manual para mayusculas */
                    }
                }
            }
        };
module.exports = {...config,content:["./website/*.html","./website/js/*.js"]};
