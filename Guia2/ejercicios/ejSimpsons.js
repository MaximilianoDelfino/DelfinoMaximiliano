fetch("https://thesimpsonsapi.com/api/characters")
.then(Response => Response.json())
.then(data => {
    console.log(data);
    return data;
})

// .then(data => document.getElementById("resultado").innerHTML = data.results[0].name)

// .then(data => {    
//   let primerosCinco = data.results.slice(0,5);
//   primerosCinco.forEach(data => {
//   document.getElementById("resultado").innerHTML += `<p>${data.name}</p>`;    
// });
// })

// .then(data => {
//     let mujer = data.results.filter(data => data.gender === "Female")
//    let cantidad = mujer.length;
//     document.getElementById("cantidad").innerHTML = 'la cantidad de personajes femeninos es ' + cantidad;
//     mujer.forEach(data => {
//         document.getElementById("resultado").innerHTML += `<p>${data.name}</p>`;    
//     });
// })

// .then(data => {    
//   let nombres = data.results;
//   nombres.forEach(data => {
//         document.getElementById("resultado").innerHTML += `<p>${data.name} --- ${data.occupation} </p>`;    
// });
// })

// .then(data => {
//     let masViejo = data.results.reduce((masViejo, current) => {
//         let viejo = parseInt(masViejo.age);
//         let actual = parseInt(current.age);
//         return viejo > actual ? masViejo : current;
//     });
//     document.getElementById("resultado").innerHTML = ` <p>El personaje mas viejo es ${masViejo.name} con ${masViejo.age} años.</p>`;
// })

// .then(data => {
//     let boton = document.getElementById('botonPersonaje');
//     boton.addEventListener('click',function(){
//         let personajeBuscado = document.getElementById('personajeNombre').value;
//         let encontrado = data.results.find(personajeNombre => personajeNombre.name === personajeBuscado);
//         if (encontrado === undefined) {
//             document.getElementById("resultado").innerHTML = `<p> Personaje no encontrado </p>`;
//         }else{
//             document.getElementById("resultado").innerHTML = ` <p>El nombre del personaje es ${encontrado.name}, trabaja de ${encontrado.occupation} y tiene ${encontrado.age}.</p>`;
//         };
//     });
// })

