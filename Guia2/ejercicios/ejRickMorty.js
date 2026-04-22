const boton = document.getElementById("boton")
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
//     let cantidadVivos = personajesVivos.length;
//     document.getElementById("resultado").innerHTML += `<p> Personajes vivos en esta página: ${cantidadVivos} </p>`
//     personajesVivos.forEach(element => {
//        document.getElementById("resultado").innerHTML += `<p>${element.name} </p>`;
//     });
//  })

// .then (data => {
//     let personajesHumanos = data.results.filter(data => data.species == "Human");
//     personajesHumanos.forEach(element => {
//        document.getElementById("resultado").innerHTML += `<p>${element.name} - ${element.species} </p>`;
//     });
//  })

// boton?.addEventListener("click",function(){
//     let idPersonaje = Number(document.getElementById("personajeId").value);
//     fetch(`https://rickandmortyapi.com/api/character/${idPersonaje}`)
//     })
//     .then (personaje => {
//         document.getElementById("resultado").innerHTML=`<img src="https://rickandmortyapi.com/api/character/avatar/${idPersonaje}.jpeg">
//         <p>${personaje.name} - ${personaje.species} - ${personaje.status}</p>`;
//     })
//     .catch(error =>{
//         document.getElementById("resultado").innerHTML = `<img src="https://upload.wikimedia.org/wikipedia/commons/f/f7/Windows_10_%26_11_BSOD_%28new_version%29.png" style="width: 500px;">
//         <p>No se encontró el personaje</p>`
//     })
// })

// .then (data => {
//     let ordenEpisodios = [...data.results].sort((a,b) => {
//         let episodios1 = a.episode.length;
//         let episodios2 = b.episode.length;
//         return episodios2 - episodios1;
//     });
//     ordenEpisodios.forEach(personajes => {
//         let cantEpisodios = personajes.episode.length;
//         document.getElementById("resultado").innerHTML += `<p>${personajes.name} aparece en ${cantEpisodios} episodios</p>`
//     })
// })

// .then (data => {
//     let tieneImagen = (imagen) => (imagen.image) !== "";
//     let imagenes = data.results.every(tieneImagen);
//     if (imagenes) {
//         document.getElementById("resultado").innerHTML += `<p>✅ Todos los personajes tienen imagen</p>` 
//     }else{   
//         document.getElementById("resultado").innerHTML += `<p>⚠️ Hay personajes sin imagen</p>` 
//     };
// })

boton?.addEventListener("click",function(){
    let nombrePersonaje = document.getElementById("personajeNombre").value;
    fetch(`https://rickandmortyapi.com/api/character?name=${nombrePersonaje}`)
    .then(respuesta => respuesta.json())
    .then(data => {
        document.getElementById("resultado").innerHTML = "";
        data.results.forEach(element => {
            let personajeId = element.id;
            document.getElementById("resultado").innerHTML += `<img src="https://rickandmortyapi.com/api/character/avatar/${personajeId}.jpeg">
            <p>${nombrePersonaje} - ${element.status}</p>`
        });
    })
});