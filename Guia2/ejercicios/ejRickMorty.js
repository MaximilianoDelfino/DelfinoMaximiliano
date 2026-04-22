fetch("https://rickandmortyapi.com/api/character")
.then(Response => Response.json())
.then(data => {
    console.log(data);
    return data;
})

// .then(data => document.getElementById("resultado").innerHTML = data.results[0].name)

// .then(data => {
//     let personajes = data.results.slice(0,5);
//     personajes.forEach(element => {
//         document.getElementById("resultado").innerHTML += `<p> ${element.name} - ${element.status} </p>`;
//     });
// })

// .then (data => {
//     let personajesVivos = data.results.filter(data => data.status === "Alive");
//     personajesVivos.forEach(element => {
//         document.getElementById("resultado").innerHTML += `<p> ${element.name} </p>`;
//     })
// })

