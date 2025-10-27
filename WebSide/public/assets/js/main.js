// public/assets/js/main.js
// placeholder, used by pages if needed
function toast(msg, type='success'){
  if(window.Swal) {
    Swal.fire({ toast:true, title:msg, icon:type, position:'top-end', timer:2500, showConfirmButton:false });
  } else {
    alert(msg);
  }
}
